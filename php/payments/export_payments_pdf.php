<?php
session_start();
require_once '../../php/config/config.php';
require_once '../libs/tcpdf/tcpdf.php';
date_default_timezone_set('Europe/Moscow');
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  exit('Доступ запрещён');
}
$conn = db_connect();
if (!$conn) exit('Ошибка подключения к базе данных');
$where_clauses = [];
$params = [];
$param_index = 1;
$search_client = $_GET['search_client'] ?? '';
$search_booking = $_GET['search_booking'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$select_type = $_GET['select_type'] ?? 'all-types';
$select_status = $_GET['select_status'] ?? 'all-statuses';
$select_payment_method = $_GET['select_payment_method'] ?? 'all-methods';
$amount_from = $_GET['amount_from'] ?? '';
$amount_to = $_GET['amount_to'] ?? '';
if ($search_client) {
  $where_clauses[] = "(client_full_name ILIKE $" . $param_index . " OR client_phone ILIKE $" . ($param_index + 1) . ")";
  $params[] = '%' . $search_client . '%';
  $params[] = '%' . $search_client . '%';
  $param_index += 2;
}
if ($search_booking && is_numeric($search_booking)) { $where_clauses[] = "booking_id = $" . $param_index++; $params[] = (int)$search_booking; }
if ($date_from) { $where_clauses[] = "payment_date >= $" . $param_index++; $params[] = $date_from; }
if ($date_to) { $where_clauses[] = "payment_date < ($" . $param_index++ . "::date + interval '1 day')"; $params[] = $date_to; }
if ($select_type !== 'all-types') { $where_clauses[] = "operation_type = $" . $param_index++; $params[] = $select_type; }
if ($select_status !== 'all-statuses') { $where_clauses[] = "payment_status = $" . $param_index++; $params[] = $select_status; }
if ($select_payment_method !== 'all-methods') { $where_clauses[] = "payment_method = $" . $param_index++; $params[] = $select_payment_method; }
if (is_numeric($amount_from)) { $where_clauses[] = "amount >= $" . $param_index++; $params[] = (float)$amount_from; }
if (is_numeric($amount_to)) { $where_clauses[] = "amount <= $" . $param_index++; $params[] = (float)$amount_to; }
$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
$sql = "SELECT transaction_id, payment_date, client_full_name, booking_id, payment_method, operation_type, amount, payment_status 
        FROM public.payments_view $where_sql ORDER BY payment_date DESC";
$result = pg_query_params($conn, $sql, $params);
$payments = pg_fetch_all($result) ?: [];
pg_close($conn);
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetTitle('Отчёт по платежам');
$pdf->SetMargins(10, 15, 10);
$pdf->setPrintHeader(false);
$pdf->AddPage();
$pdf->SetFont('dejavusans', 'B', 16);
$pdf->SetTextColor(31, 41, 55); 
$pdf->Cell(0, 10, 'Платежи', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(107, 114, 128);
$pdf->Cell(0, 5, 'Дата формирования (МСК): ' . date('d.m.Y H:i'), 0, 1, 'L');
$pdf->Ln(5);
$w = [
  'id' => '7%',
  'date' => '15%',
  'client' => '18%',
  'booking' => '8%',
  'method' => '15%',
  'type' => '12%',
  'amount' => '12%',
  'status' => '13%'
];
$html = '<style>
    table { border-collapse: collapse; }
    th { background-color: #f9fafb; color: #6b7280; font-weight: bold; font-size: 7pt; border: 1px solid #e5e7eb; text-align: center; }
    td { color: #111827; font-size: 8pt; border: 1px solid #e5e7eb; vertical-align: middle; }
  </style>
  <table cellpadding="5">
    <thead>
      <tr>
        <th width="'.$w['id'].'">ID</th>
        <th width="'.$w['date'].'">ДАТА И ВРЕМЯ</th>
        <th width="'.$w['client'].'">КЛИЕНТ</th>
        <th width="'.$w['booking'].'">БРОНЬ</th>
        <th width="'.$w['method'].'">СПОСОБ</th>
        <th width="'.$w['type'].'">ТИП</th>
        <th width="'.$w['amount'].'">СУММА</th>
        <th width="'.$w['status'].'">СТАТУС</th>
      </tr>
    </thead>
    <tbody>';
foreach ($payments as $p) {
  $amountColor = $p['amount'] >= 0 ? '#10b981' : '#ef4444';
  $amountPrefix = $p['amount'] > 0 ? '+ ' : '';
  $statusStyle = match($p['payment_status']) {
    'Успешно' => 'background-color:#d1e7dd; color:#065f46;',
    'Отклонено' => 'background-color:#f8d7da; color:#842029;',
    'В обработке' => 'background-color:#ffedd5; color:#9a3412;',
    default => ''
  };
  $html .= '<tr>
    <td width="'.$w['id'].'" style="text-align:center;">'.$p['transaction_id'].'</td>
    <td width="'.$w['date'].'" style="text-align:center;">'.date('d.m.Y H:i', strtotime($p['payment_date'])).'</td>
    <td width="'.$w['client'].'"> '.$p['client_full_name'].'</td>
    <td width="'.$w['booking'].'" style="text-align:center;">'.$p['booking_id'].'</td>
    <td width="'.$w['method'].'" style="text-align:center;">'.$p['payment_method'].'</td>
    <td width="'.$w['type'].'" style="text-align:center;">'.$p['operation_type'].'</td>
    <td width="'.$w['amount'].'" style="text-align:right; color:'.$amountColor.'; font-weight:bold;">'.$amountPrefix.number_format($p['amount'], 0, '', ' ').'</td>
    <td width="'.$w['status'].'" style="text-align:center;"><span style="'.$statusStyle.'"> '.$p['payment_status'].' </span></td>
  </tr>';
}
$html .= '</tbody></table>';
$pdf->SetFont('dejavusans', '', 9);
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('payments_report_' . date('Ymd_His') . '.pdf', 'D');
exit;