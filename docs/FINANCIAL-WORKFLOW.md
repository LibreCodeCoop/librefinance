<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Fluxos comerciais, financeiros e contábeis no LibreFinance

A navegação reflete a atividade, mas mantém documentos e lançamentos separados.

| Área | Quando utilizar | Resultado |
| --- | --- | --- |
| Faturas de venda | Cobrar um cliente, com itens, tributos e vencimento | Rascunho comercial. Quando confirmado, cria uma conta a receber vinculada |
| Documentos de compra | Registrar a fatura/compra de fornecedor | Rascunho comercial. Quando confirmado, cria uma conta a pagar vinculada |
| Contas a pagar e receber | Consultar vencimentos e compromissos ou criar um compromisso sem documento | Títulos vinculados e avulsos, claramente identificados |
| Bancos e conciliação | Revisar extratos e transferências | Conferência de movimentos bancários |
| Lançamentos contábeis | Contabilizar uma entrada ou saída já realizada | Partidas dobradas imutáveis, passíveis de associação a documentos |

## Quando há uma fatura ou compra

1. Cadastre o documento em Faturas de venda ou Documentos de compra.
2. Confirme-o. O servidor cria exatamente um título financeiro vinculado, na mesma transação SQL.
3. Se o pagamento já está contabilizado, abra o documento, selecione conta, valor e o lançamento existente compatível. A associação não movimenta dinheiro novamente.
4. Caso contrário, registre a movimentação em Lançamentos contábeis e retorne para associá-la.
5. Confira o título financeiro e concilie o extrato bancário, quando aplicável.

A API impede baixa direta ou cancelamento independente de um título vinculado à fatura. Também impede reutilização de lançamento e alocação acima do saldo.

## Quando não há documento comercial

Compromissos independentes, como aluguel e obrigações não documentadas por fatura, podem ser cadastrados diretamente em Contas a pagar e receber. A baixa administrativa **não** cria lançamento contábil nem movimentação bancária. Não confunda 'liquidado administrativamente' com 'contabilizado e conciliado'.

## Prevenção de duplicidade e limitações

- O servidor garante relacionamento um-para-um entre documento confirmado e título financeiro.
- A interface informa a origem do título, permite navegar até o documento e exige selecionar lançamento existente, sem lançar novamente.
- Não existe detecção universal de compromissos economicamente duplicados criados manualmente com outras referências. Números, valores ou datas iguais não bastam para inferir identidade; esse caso requer reconciliação documental explícita, com exceções auditáveis.
- Documentos históricos sem vínculo verificado não são associados automaticamente por coincidência de valores ou datas.

## Compatibilidade

As tabelas, permissões e identificadores internos de módulo foram preservados. O endereço antigo de faturas com view=invoicing redireciona internamente para Faturas de venda quando autorizado. As entradas view=sales e view=purchases utilizam o mesmo backend, com filtro por tipo aplicado antes da paginação.
