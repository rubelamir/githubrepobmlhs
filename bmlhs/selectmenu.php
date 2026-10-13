<?php
include "database/connection.php";

$type = (string) ($_POST['type'] ?? '');
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$optionMap = [
    'district' => ['districts', 'division_id', 'bn_name', true],
    'upazilla' => ['upazilas', 'district_id', 'bn_name', true],
    'post_office' => ['postoffice', 'upazilas_id', 'name', false],
    'sections' => ['sections', 'class_id', 'section_name_ban', true],
    'admission_sections' => ['sections', 'class_id', 'section_name_ban', true],
];

$str = '';
if (isset($optionMap[$type]) && $id) {
    [$table, $column, $labelColumn, $hasValue] = $optionMap[$type];
    $orderBy = in_array($type, ['sections', 'admission_sections'], true)
        ? ' ORDER BY sort_order ASC, id ASC'
        : '';
    $activeSql = in_array($type, ['sections', 'admission_sections'], true)
        ? ' AND is_active = 1'
        : '';
    $statement = $conn->prepare("SELECT id, {$labelColumn} FROM {$table} WHERE {$column} = ?{$activeSql}{$orderBy}");
    $statement->bind_param('i', $id);
    $statement->execute();
    $query = $statement->get_result();
    if ($query->num_rows > 0) {
        $str .= "<option value=''>Select</option>";
        while ($row = $query->fetch_assoc()) {
            $value = $hasValue ? " value='" . (int) $row['id'] . "'" : '';
            $label = htmlspecialchars((string) $row[$labelColumn], ENT_QUOTES, 'UTF-8');
            $str .= "<option{$value}>{$label}</option>";
        }
    }
    if ($type === 'admission_sections') {
        $str .= "<option value='others'>নতুন ভর্তি</option>";
    }
    $statement->close();
}

echo $str;
