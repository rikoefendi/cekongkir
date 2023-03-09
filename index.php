<?php
require './bukasend.php';

$bukasend = new BukaSend();

$bukasend->setHeaders($_SERVER);

$couriers = $bukasend->getCouriers(array(
    'province' => 'Daerah Istimewa Yogyakarta',
    'city' => 'Sleman',
    'district' => 'Depok'
), array(
    'province' => 'Jawa Tengah',
    'city' => 'Kab. Semarang',
    'district' => 'Tengaran'
), 1000);
// $addresess = $bukasend->getAddresess('condong catur');
echo "<pre>";
var_dump($couriers);
echo "</pre>";
