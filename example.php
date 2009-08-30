<?php
error_reporting(E_ALL);
require('PHPGatewayInterface.php');

$options = array
(
    'SCRIPT_FILENAME'   => '/usr/lib/cgi-bin/cvsweb/cvsweb.cgi',
    'REQUEST_URI'       => '/cgi-bin/cvsweb/cvsweb.cgi',
    'SCRIPT_NAME'       => '/cgi-bin/cvsweb/cvsweb.cgi',
    'PHP_SCRIPT'        => basename(__FILE__),
    'DEBUG'             => true,
);

$cgi = new PHPGatewayInterface($options);

/*
header('Content-Type: '. $cgi->getContentType());
echo $cgi->getBody();
/**/

/**/
$content = $cgi->getDiv(true);

$html = <<<HTML
<html>
<head>
    <title>cvsweb</title>
    <link rel="stylesheet" href="style.css" type="text/css">
</head>
<body>
$content
</body>
</html>
HTML;

switch ($cgi->getContentType())
{
    case 'text/html':
    case 'text/plain':
        echo $html;
        break;

    default:
        header('Content-Type: '. $cgi->getContentType());
        echo $content;
}
/**/

/*
echo '<pre>';
echo $cgi->getHeader();
echo '</pre>';
*/
?>

