<?php

namespace GlpiPlugin\Escalations;

use Change;
use CommonITILObject;
use Problem;
use Project;
use ProjectState;
use ProjectTask;
use Ticket;

/** Estados da origem nativa usados na abertura, no motor e no despacho. */
final class NativeItem
{
    private const TYPES = [
        'ticket' => [Ticket::class, 'chamado', 'solucionado'],
        'problem' => [Problem::class, 'problema', 'concluído'],
        'change' => [Change::class, 'mudança', 'concluída'],
        'project' => [Project::class, 'projeto', 'concluído'],
        'projecttask' => [ProjectTask::class, 'tarefa de projeto', 'concluída'],
    ];

    public static function terminalStatuses(string $source): array
    {
        // O contrato do Escalations mantém OBSERVED ativo em problemas e mudanças.
        $solved = [CommonITILObject::SOLVED, CommonITILObject::CLOSED];
        return $source === 'change' ? [...$solved, Change::CANCELED, Change::REFUSED] : $solved;
    }

    /** @return 'deleted'|'cancelled'|'refused'|'resolved'|null */
    public static function outcome(string $source, int $id): ?string
    {
        global $DB;
        $type = self::TYPES[$source][0] ?? null;
        if ($type === null) {
            return null;
        }
        $itil = is_a($type, CommonITILObject::class, true);
        $fields = $itil ? ['status', 'is_deleted'] : ['percent_done', 'projectstates_id', 'is_deleted'];
        $rows = $DB->request(['SELECT' => $fields, 'FROM' => $type::getTable(), 'WHERE' => ['id' => $id]]);
        foreach ($rows as $row) {
            if ((bool) $row['is_deleted']) {
                return 'deleted';
            }
            if (!$itil) {
                if ((int) $row['percent_done'] >= 100) {
                    return 'resolved';
                }
                $state = (int) $row['projectstates_id'];
                return $state && countElementsInTable(ProjectState::getTable(), ['id' => $state, 'is_finished' => 1])
                    ? 'resolved' : null;
            }
            $status = (int) $row['status'];
            if (!in_array($status, self::terminalStatuses($source), true)) {
                return null;
            }
            return match ($status) {
                Change::CANCELED => 'cancelled',
                Change::REFUSED => 'refused',
                default => 'resolved',
            };
        }
        return 'deleted';
    }

    public static function isClosed(array $e): bool
    {
        return self::outcome($e['source'], (int) $e['items_id']) !== null;
    }

    /** Usa a transição do plugin sob a trava da ocorrência. */
    public static function close(?array $e): bool
    {
        if (!$e || !in_array($e['state'], ['open', 'acked'], true)) {
            return false;
        }
        $itemId = (int) $e['items_id'];
        $outcome = self::outcome($e['source'], $itemId);
        if ($outcome === null) {
            return false;
        }
        [, $label, $done] = self::TYPES[$e['source']];
        $id = (int) $e['id'];
        if ($outcome === 'resolved') {
            Escalation::resolve($id, "$label $done");
        } else {
            $reason = match ($outcome) {
                'cancelled' => 'foi cancelada',
                'refused' => 'foi recusada',
                default => 'foi excluído',
            };
            Escalation::cancel($id, "$label #$itemId $reason.");
        }
        return true;
    }
}
