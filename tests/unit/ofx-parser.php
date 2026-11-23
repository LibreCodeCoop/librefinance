<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/Domain/Banking/OfxStatementParser.php';
use OCA\LibreFinance\Domain\Banking\OfxStatementParser;
function check(bool $value,string $reason):void {if(!$value)throw new RuntimeException($reason);}
function reject(string $value):void {
    try{OfxStatementParser::parse($value);}catch(DomainException){return;}
    throw new RuntimeException('Invalid OFX accepted');
}
$body=<<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
ENCODING:USASCII
CHARSET:1252

<OFX><STMTRS><CURDEF>BRL
<BANKTRANLIST><DTSTART>20261120
<STMTTRN><DTPOSTED>20261120124500[-3:BRT]
<TRNAMT>-25.90
<FITID>ofx-id-one
<MEMO>Pagamento de energia
</STMTTRN>
<STMTTRN><DTPOSTED>20261122
<TRNAMT>150,00
<FITID>ofx-id-two
<NAME>Recebimento de cliente
</STMTTRN></BANKTRANLIST></STMTRS></OFX>
OFX;
$one=OfxStatementParser::parse($body);
check($one['currency']==='BRL','Currency');
check(count($one['rows'])===2,'Count');
check($one['rows'][0]['amount_minor']===-2590,'Negative cents');
check($one['rows'][1]['amount_minor']===15000,'Comma decimal');
check($one['rows'][0]['date']==='2026-11-20','Time zone');
$xml=<<<'OFX'
<?xml version="1.0" encoding="UTF-8"?>
<OFX><STMTRS><CURDEF>BRL</CURDEF><BANKTRANLIST>
<STMTTRN><DTPOSTED>20261122</DTPOSTED><TRNAMT>1.05</TRNAMT>
<FITID>id&lt;123</FITID><NAME>Loja &amp; Cia</NAME></STMTTRN>
</BANKTRANLIST></STMTRS></OFX>
OFX;
$two=OfxStatementParser::parse($xml);
check($two['rows'][0]['amount_minor']===105,'XML cents');
check($two['rows'][0]['description']==='Loja & Cia','XML escaped text');
check(str_starts_with($two['rows'][0]['id'],'ofx-'),'Hashed weird FITID');
$fractionDate = str_replace('20261120124500[-3:BRT]', '20261120124500.000[-3:BRT]', $body);
check(OfxStatementParser::parse($fractionDate)['rows'][0]['date']==='2026-11-20',
    'Fractional seconds with timezone');
$zeros = str_replace('-25.90','-25.900000',$body);
check(OfxStatementParser::parse($zeros)['rows'][0]['amount_minor']===-2590,
    'Extra zero decimal places are safe');
reject(str_replace('-25.90','-25.900001',$body));
reject(str_replace('20261120124500[-3:BRT]','202611201245[-3:BRT]',$body));
reject(str_replace('20261120124500[-3:BRT]','20261120999999[-3:BRT]',$body));
reject(str_replace('20261120','20260230',$body));
reject(str_replace('150,00','0.00',$body));
reject(str_replace('ofx-id-two','ofx-id-one',$body));
reject(str_replace('</BANKTRANLIST>','</BANKTRANLIST><BANKTRANLIST></BANKTRANLIST>',$body));
reject('<!DOCTYPE OFX [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>' . $body);
reject(str_repeat('x',262145));
echo "OFX parser: 19 positive and negative assertions passed\n";
