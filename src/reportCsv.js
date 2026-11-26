/* SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors */
/* SPDX-License-Identifier: AGPL-3.0-or-later */

export function cashFlowCsv(result) {
  if (!result || typeof result !== 'object') {
    throw new Error('Invalid result structure');
  }

  const { currency, bankAccountId, from, through, basis, isForecast, openingMinor, netMinor, closingMinor, months } = result;

  if (typeof currency !== 'string' || !/^[A-Z]{3}$/.test(currency)) {
    throw new Error('Invalid currency ISO code');
  }

  if (!Number.isInteger(bankAccountId) || bankAccountId < 0) {
    throw new Error('Invalid bank account id');
  }

  const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
  if (typeof from !== 'string' || !dateRegex.test(from) || typeof through !== 'string' || !dateRegex.test(through)) {
    throw new Error('Invalid canonical date format');
  }

  if (basis !== 'realized_bank_postings' || isForecast !== false) {
    throw new Error('Invalid basis or forecast flag');
  }

  if (!Number.isSafeInteger(openingMinor) || !Number.isSafeInteger(netMinor) || !Number.isSafeInteger(closingMinor)) {
    throw new Error('Invalid safe integer minor units');
  }

  const openingBig = BigInt(openingMinor);
  const netBig = BigInt(netMinor);
  const closingBig = BigInt(closingMinor);

  if (openingBig + netBig !== closingBig) {
    throw new Error('Opening plus net must equal closing');
  }

  if (!Array.isArray(months)) {
    throw new Error('Months must be an array');
  }

  let sumMonthlyNet = 0n;
  const monthRegex = /^\d{4}-\d{2}$/;
  let previousMonth = '';

  const processedMonths = months.map(m => {
    if (!m || typeof m !== 'object') {
      throw new Error('Invalid month object');
    }
    const { month, netMinor: mNet } = m;
    if (typeof month !== 'string' || !monthRegex.test(month)) {
      throw new Error('Invalid month format');
    }
    if (month <= previousMonth) {
      throw new Error('Months must be unique and strictly ascending');
    }
    previousMonth = month;

    if (!Number.isSafeInteger(mNet)) {
      throw new Error('Invalid month net minor safe integer');
    }
    sumMonthlyNet += BigInt(mNet);
    return { month, netMinor: mNet };
  });

  if (sumMonthlyNet !== netBig) {
    throw new Error('Sum of monthly net must equal total net');
  }

  const rows = [
    'currency,bankAccountId,from,through,metric,month,amountMinor'
  ];

  const baseMeta = `${currency},${bankAccountId},${from},${through}`;

  rows.push(`${baseMeta},opening,,${openingMinor}`);
  rows.push(`${baseMeta},net,,${netMinor}`);
  rows.push(`${baseMeta},closing,,${closingMinor}`);

  for (const m of processedMonths) {
    rows.push(`${baseMeta},monthly_net,${m.month},${m.netMinor}`);
  }

  return rows.join('\r\n') + '\r\n';
}
