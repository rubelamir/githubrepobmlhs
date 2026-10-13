<?php
function english_to_bengali($strTextToChange)
{
$bengali_numbers = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
$english_numbers = range(0, 9);
return str_replace($english_numbers, $bengali_numbers, $strTextToChange);
}

function test_input($data)
{
$data = trim($data);
$data = stripslashes($data);
$data = htmlspecialchars($data);
return $data;
}
?>

<script>

const myAlert = document.getElementById('alert');
if (myAlert) {
setTimeout(() => {
myAlert.style.display = 'none';
}, 5000);
}
</script>

