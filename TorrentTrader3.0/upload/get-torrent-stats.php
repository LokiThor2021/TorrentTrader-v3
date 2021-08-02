<?php
// AJAX Torrent Scrape - Get Stats - BETA - May 19, 2020
// Include database
$dsn       = 'mysql:dbname=tt;host=localhost;charset=utf8';
$user      = 'homestead';
$password  = 'secret';
$torrentid = isset($_GET['id'])? (int) $_GET['id'] :0; // Get torrent ID ?id=
try {
    $conn = new PDO($dsn, $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
}
catch (PDOException $e) {
    die('Unable to connect to database');
}
$limit = 1;
$stmt = $conn->prepare("SELECT id, seeders, leechers FROM torrents WHERE id = :torrentid LIMIT :datalimit");
$stmt->bindParam(":datalimit", $limit, PDO::PARAM_INT);
$stmt->bindParam(":torrentid", $torrentid, PDO::PARAM_INT);
$stmt->execute();
$userData = array();
if ($stmt->rowCount() > 0) {
    $usersactive = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($usersactive as $row => $data) {
        $data['seeders']      = $row['seeders'];
        $data['leechers']     = $row['leechers'];
        array_push($userData, $usersactive);
    }
}
if (isset($_GET['return'])){
    header("location: torrents-details.php?id=$torrentid");
}
echo json_encode($usersactive, JSON_UNESCAPED_SLASHES);



