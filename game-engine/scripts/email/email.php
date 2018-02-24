<?php
if (count($argv) != 3)
{
  exit(10);
}

// In argv[1] we receive the destination address
$to = $argv[1];

// In argv[2] the message
$msg = $argv[2];

// use wordwrap() if lines are longer than 70 characters
$msg = wordwrap($msg,70);

// From & subject are fixed for the moment
$headers = "From: registrar@exmachinis.com";
$subject = "Command result";

// send email
//mail($to, $subject, $msg, $headers);
mail("crodnun@yahoo.es", $subject, $msg, $headers);
?>
