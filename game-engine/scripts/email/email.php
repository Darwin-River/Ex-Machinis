<?php
if (count($argv) != 4)
{
  exit(10);
}

// In argv[1] we receive the destination address
$to = $argv[1];

// In argv[2] the message
$msg = $argv[3];

// use wordwrap() if lines are longer than 70 characters
$msg = wordwrap($msg,70);

// From name is received at $arv[2]
$headers = "From: " . $argv[2] . "@exmachinis.com";

// Subject is fixed for the moment
$subject = "Command result";

// send email
mail($to, $subject, $msg, $headers);
?>
