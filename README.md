# Escalations — plugin GLPI 11 e 12

> Plugin independente para o [GLPI](https://glpi-project.org) 11/12. Licença GPLv3+.

Escalonamento de áreas por **chamados** (SLA do GLPI), **eventos** (webhook do Zabbix e outros), com hierarquia de níveis, avisos por **e-mail** (com o histórico das escaladas), **pop-up no GLPI**, **Teams** e **WhatsApp**, ACK que interrompe a cadeia, **logs rastreáveis** e monitoramento de **SLO/SLA**.

Chave/pasta: `escalations` · namespace `GlpiPlugin\Escalations` · tabelas `glpi_plugin_escalations_*` · versão 0.4.0.

## O que faz

| Recurso | Como |
|---|---|
| Hierarquia | Níveis N1 → N2 → … com alvos (usuário, grupo, gestores do grupo, gestor de quem já foi acionado), tempo para subir e canais por nível. Regras dizem quando ela vale (origem, severidade, entidade, categoria, texto). |
| Fontes | **Chamado**: ao consumir X% do prazo de solução (SLA) ou sem responsável há N min. **Problema, mudança, projeto e tarefa de projeto** do GLPI: ao consumir X% do prazo. **Item de plugin**: via `Api` (abaixo). **Evento**: `front/webhook.php` (token). **Manual**: botão "Escalar agora". |
| E-mail | HTML com severidade, resumo, contexto, prazos de ACK/resolução, **histórico das escaladas anteriores**, próximo passo e botões Assumir / Abrir no GLPI. Assunto, abertura e rodapé editáveis. |
| Pop-up | `public/js/escalations.js` consulta `front/pending.php` a cada ~20 s; modal em destaque com histórico e botões Assumir / Abrir ficha / Adiar; alerta sonoro; fica até o ACK. |
| Teams | Cartão adaptável via webhook do Workflows (Power Automate). |
| WhatsApp | **Meta Cloud API** (com modelo aprovado ou texto livre) **ou gateway próprio** (Evolution, Z-API, WPPConnect…: URL + cabeçalho + corpo JSON com `{to}` e `{text}`). Resposta `ACK 123` assume pelo WhatsApp (`front/inbound.php`, assinatura validada). |
| ACK / ciclo | Aguardando ACK → Reconhecido → Resolvido / Cancelado. ACK, resolver e silenciar interrompem a escalada. Evento recuperado e chamado solucionado encerram sozinhos. Repetições do mesmo evento são agrupadas (deduplicação). |
| Permissões | Em camadas, da mais geral à mais específica: (1) **perfil** (editado em **Escalations → Permissões**, com modelos prontos; é o mesmo direito da aba Escalations em Perfis, que continua funcionando): ler, atualizar (vale como todas as ações), criar, administrar, ver todos e os de ação **reconhecer, resolver, silenciar, cancelar**, **logs** e **SLO/gaps**; (2) **perfil no plugin** (Escalations → Permissões): "somente notificado" e **escopo de hierarquias**; (3) **usuário no plugin**: permitir ou negar cada ação, "somente notificado" e escopo próprio; (4) **papel no nível** (Hierarquias): **escalado** (pode agir no escalonamento em que foi acionado) ou **somente notificado** (recebe o aviso, sem botão de ACK, sem ação). Negar vence tudo; somente notificado bloqueia as ações; administrar e criar vêm só do perfil. A tela mostra as permissões efetivas de cada pessoa das hierarquias. |
| Mensagens enviadas | **Caixa de saída**: guarda a cópia exata de cada e-mail (HTML), cartão do Teams e mensagem de WhatsApp, com destinatário, rastreio e situação. **Modo simulação** (Configurações): canais escolhidos montam e guardam a mensagem mas **não enviam** (para laboratório sem SMTP, ou antes de configurar Teams e WhatsApp). |
| Gaps | Tela **Análise de gaps**: onde a escalada para (funil por nível), tempo por nível, mapa de calor dia × hora, quem foi acionado e não assumiu, canais que falham, ruído, causas dos estouros de SLO e **buracos de cobertura** (nível sem usuário ativo, usuário sem e-mail/celular, canal desligado, regra que nunca dispara, chamado crítico sem regra, SLO de ACK menor que a espera do N1). |
| Falhas | Cada envio tem até N tentativas (espera 1, 5, 15 min) e, falhando em definitivo, aciona o **canal reserva**. |
| Logs rastreáveis | Código de rastreio `ESC-AAMMDD-XXXXX` em todas as mensagens. Cada ação é uma linha numerada por rastreio, **encadeada por hash**: edição ou exclusão no banco é detectada (botão "Verificar integridade"). Segredos nunca entram no log. Filtros, gaveta com detalhe e exportação CSV. |
| SLO | Por hierarquia: SLO de ACK e de resolução, meta (%), janela (dias), alerta (% do prazo). Alerta e estouro avisam o dono da hierarquia. Painel com cumprimento, MTTA/MTTR, **orçamento de erro**, **burn rate de 24 h**, tendência diária. |
| SLA | Lê o prazo de solução (SLA) nativo dos chamados: cumprido, estourado, abertos em risco, estouros por entidade. |
| Saúde do motor | Banner vermelho em todas as telas se o cron não roda há mais de 3 min. |

## Recuperação de entregas

O nível, o marco de SLO e as respectivas entregas são gravados juntos. A ficha, o pop-up de ACK, o webhook, a API e o ACK por WhatsApp tentam processar a fila da ocorrência após a gravação. Se houver uma transação do chamador em andamento, o motor assume o processamento após o commit.

Falhas retornadas pelo canal mantêm as tentativas e o canal reserva configurados. Uma tentativa interrompida durante o envio fica **sem confirmação** na ficha. Como o provedor pode tê-la aceitado, o motor não repete essa tentativa nem aciona o canal reserva automaticamente. O mesmo vale para entradas antigas em fila cujo estado não permite saber se o transporte já foi chamado.

Antes de cada tentativa, o motor confere se o aviso ainda é pertinente. O ACK encerra pedidos de ação pendentes e preserva avisos informativos válidos, como o SLO de resolução. Após o encerramento da ocorrência, os avisos de prazo ainda em curso são dispensados. Estouro de SLO já registrado continua sendo um fato que pode ser comunicado pelos canais externos.

### Modo simulação

A simulação é por canal (Configurações → Caixa de saída e modo simulação). O canal escolhido monta a mensagem e a grava na Caixa de saída com situação **Simulada**, mas não chama SMTP, Teams nem WhatsApp. A Caixa de saída também guarda o conteúdo e o erro quando o envio simulado falha, por exemplo, usuário sem e-mail ou celular.

Uma falha de canal simulado não repete tentativas nem aciona um canal reserva real. O canal reserva só é usado na simulação quando ele também estiver marcado como simulado.

O ACK e a resolução continuam gerando seus avisos no Teams; se o Teams estiver em simulação, eles ficam na Caixa de saída como simulados. No WhatsApp, a resposta automática de confirmação do ACK também é suprimida. Ela não entra na Caixa de saída, que permanece com o propósito original de guardar as mensagens dos níveis e avisos; a supressão fica registrada no log.

Os botões **Testar** em Configurações são envios reais e explícitos, mesmo com a simulação ligada. Use-os apenas para validar o provedor.

## Para outros plugins (API)

Qualquer plugin, atual ou futuro, escala itens com prazo sem conhecer hierarquias (`src/Api.php`; nunca lança exceção):

```php
use GlpiPlugin\Escalations\Api;
if (class_exists(Api::class) && Api::available()) {
    // já está crítico: escala agora (agrupa se já houver aberto com a mesma chave)
    Api::escalate(['plugin' => 'customer360', 'type' => 'contrato_vencendo', 'key' => 'contrato:' . $id, 'title' => '...',
                   'severity' => 4, 'entities_id' => 0, 'summary' => '...', 'facts' => ['Conta' => '...'], 'url' => '/plugins/customer360/front/contrato.php?id=' . $id]);
    // ou deixa o motor acompanhar o prazo; a regra define a % do prazo que dispara (sem %, dispara ao vencer)
    Api::track([... mesmos campos ..., 'start_at' => '2026-01-01', 'due_at' => '2026-12-31']);
    // quando o problema acaba (renovou, entregou, fechou)
    Api::resolve('customer360', 'contrato_vencendo', 'contrato:' . $id);
}
```

Os tipos usados aparecem sozinhos em Hierarquias → Regras (origem "Item de plugin"); o administrador liga cada tipo a uma hierarquia e define severidade mínima e % do prazo. Sem regra, nada é escalado (o log registra o aviso uma vez por dia). Quem chama é responsável por `resolve` quando o item deixa de estar crítico.

### Compatibilidade das chaves

Use a mesma referência completa em `track`, `escalate` e `resolve`. No webhook, envie a mesma `dedup_key` na abertura e na recuperação, ou repita `source`, `trigger_id` e `host` quando a chave for gerada. Sem `trigger_id`, o título faz parte da chave gerada. Campos de texto usados como chave continuam aceitando espaços nas pontas conforme a normalização da integração.

Chaves que cabem no formato histórico continuam válidas. Para entradas longas ou composições com delimitadores ambíguos, o plugin usa uma representação com hash e reserva o prefixo `~1:` para uso interno. Essas identidades exigem a mesma grafia da entrada normalizada, incluindo maiúsculas e acentos nas referências. No catálogo de regras, nomes de plugin acima de 40 caracteres ganham uma chave própria. Tipos acima de 60 caracteres continuam roteados pelo prefixo de 60, e a identidade de cada item inclui o tipo completo.

Os identificadores já gravados permanecem intactos. A API pode localizar uma ocorrência histórica por um vínculo único já registrado no acompanhamento. Um prefixo que perdeu caracteres em versões anteriores não prova qual item o originou. Para recuperar um evento legado desse tipo, envie o `dedup_key` histórico exato, se conhecido e fora do prefixo reservado. Se o identificador não puder ser confirmado ou se vários itens já tiverem sido agrupados sob a mesma chave, identifique a ocorrência pelo rastreio e use a ficha para resolvê-la.

## Instalar

Requisitos: GLPI 11.0 a 12.0.x e PHP 8.2 ou superior.

```bash
# no servidor do GLPI
cd /var/www/glpi/plugins
git clone https://github.com/evandroevangelista77/escalations-glpi.git escalations   # a pasta precisa se chamar "escalations"
chown -R www-data:www-data escalations
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install escalations
sudo -u www-data php bin/console plugin:activate escalations
```

Ou baixe o pacote da página de [Releases](https://github.com/evandroevangelista77/escalations-glpi/releases) e extraia em `plugins/`.

1. **Motor (cron)**: precisa rodar **a cada minuto**. Duas formas (use uma): (a) o cron do sistema chamando o GLPI inteiro, `* * * * * php /var/www/glpi/front/cron.php`, que roda a ação automática `escalationsrun` (modo externo); ou (b) só o Escalations, sem tocar nas outras ações do GLPI: `* * * * * www-data /usr/bin/php /var/www/glpi/plugins/escalations/tools/tick.php >/dev/null 2>&1`. Os dois podem coexistir: o motor tem trava e nunca roda dois ciclos juntos. Sem isso nada sobe de nível, e o banner "Motor parado" aparece em todas as telas.
2. **Escalations → Permissões**: direitos de cada perfil (são os mesmos da aba Escalations em Administração → Perfis), modelos de perfil, "somente notificado", escopo de hierarquias e exceções por usuário. No install, perfis com `config` de escrita ficam com tudo; os demais só leitura. Quem é acionado como escalado consegue dar ACK mesmo só com leitura.
3. **Escalations → Configurações**: e-mail (depende de Configurar → Notificações ligado), Teams, WhatsApp, canal reserva. Há botões de teste de cada canal.
4. **Escalations → Hierarquias**: crie a hierarquia, os níveis e as regras. O celular do usuário (campo *Celular*) é o número do WhatsApp.
5. **Zabbix**: media type Webhook postando o JSON de exemplo da tela de Configurações, com o token.

## Testar

```bash
sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/escalations/tests/smoke.php   # em um GLPI de teste, não em produção
```

Cria registros `[Teste ESC]`, valida hierarquia, abertura, deduplicação, subida de nível, ACK, SLO, cadeia de logs (inclusive detecção de adulteração) e webhook, e limpa tudo.

`tests/render.php` renderiza as 11 telas por CLI com sessão simulada (`setup`, `page <tela>`, `teardown`) e acusa qualquer erro PHP.

## Situação

- Versão **0.4.0**, testada no **GLPI 12.0** (teste funcional de 40+ verificações, renderização das telas por CLI e chamadas reais ao webhook). **Ainda não testada no GLPI 11** (declara compatibilidade 11.0 a 12.0.x).
- **Origens ativas por padrão: só o que nasce no GLPI** (chamados, problemas, mudanças, projetos e tarefas de projeto, mais o escalonamento manual). O webhook de eventos e a API para outros plugins vêm **desligados**; ligue em **Configurações → Origens ativas**.
- `webhook.php` e `inbound.php` ficam fora do login registrando-os no firewall (`Firewall::addPluginStrategyForLegacyScripts` + `SessionManager::registerPluginStatelessPath`, em `setup.php`). No GLPI 12 a variável `$SECURITY_STRATEGY` **não** vale para plugins.
- Envio real de e-mail, Teams e WhatsApp depende de você configurar cada canal; use o **modo simulação** para testar sem enviar nada. Os botões de teste são a exceção: eles enviam de verdade para validar o provedor.

## Pendências e pontos a conferir
- Resposta do WhatsApp só no provedor Meta; o gateway próprio só envia.
- Janela de 24 h da Meta: fora dela só vale modelo aprovado (campo "Modelo").
- Teams: o cartão foi montado para o webhook do Workflows; conferir no canal real.
- Fora do escopo atual: **plantão com escala** (o alvo `oncall` está reservado), ACK por link de e-mail sem login, ACK por aplicativo móvel, relatório executivo em PDF e Wazuh como fonte de eventos.
- A ponte com o plugin **Avisos** (sino do GLPI), que é opcional, está desligada por padrão (Configurações → Motor).
- E-mail com anexo/inline (logo) não incluído; o cabeçalho usa só cor da severidade.
