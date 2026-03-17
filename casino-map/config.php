<?php
$host="localhost";
$user="root";
$pass="";
$db="casino_map";

$conn=new mysqli($host,$user,$pass,$db);

if($conn->connect_error){
    die("DB Error");
}
?>