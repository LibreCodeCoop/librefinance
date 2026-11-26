/* SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors */
/* SPDX-License-Identifier: AGPL-3.0-or-later */

function validDate(value) {
  if (typeof value !== 'string' || value.length !== 10 || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [year, month, day] = value.split('-').map(Number);
  if (year < 1 || year > 9999 || month < 1 || month > 12 || day < 1) return false;
  const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
  return day <= [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1];
}

export function cashFlowCsv(result) {
  if (!result || typeof result !== 'object') {
    throw new Error('Invalid result structure');
  }

  const { currency, bankAccountId, from, through, basis, isForecast, openingMinor, netMinor, closingMinor, months } = result;

  if (typeof currency !== 'string' || currency.length !== 3 || !/^[A-Z]{3}$/.test(currency)) {
    throw new Error('Invalid currency ISO code');
  }

  if (!Number.isSafeInteger(bankAccountId) || bankAccountId < 0) {
    throw new Error('Invalid bank account id');
  }

  if (!validDate(from) || !validDate(through) || from > through) {
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
    if (typeof month !== 'string' || month.length !== 7 || !monthRegex.test(month) || !validDate(month + '-01') || month < from.slice(0, 7) || month > through.slice(0, 7)) {
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
