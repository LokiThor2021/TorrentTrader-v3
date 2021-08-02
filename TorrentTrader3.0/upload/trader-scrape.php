<?php
// AJAX Torrent Scrape - Update Stats - BETA - May 19, 2020
// Public torrents only / UDP, HTTPS, HTTP
// Takes POST by torrent ID via AJAX call, scrapes announce in torrent table
// with much better class and logic, and updates seeders/leechers in database by ID

require_once 'backend/functions.php';
$torrentid = isset($_GET['id']) ? (int) $_GET['id'] : 0; // Get torrent ID ?id=
$limit = 1;
$dsn = 'mysql:dbname=tt;host=localhost;charset=utf8';
$user = 'homestead';
$password = 'secret';
$torrentid = $_GET['id'];
try {
    $conn = new PDO($dsn, $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die('Unable to connect to database');
}
$stmt = $conn->prepare("SELECT id, announce, info_hash FROM torrents WHERE external = 'yes' AND id = :torrentid LIMIT 1");
$stmt->bindValue(':torrentid', $torrentid, PDO::PARAM_INT);
$stmt->execute();


class ScraperException extends Exception
{
    private $connectionerror;

    public function __construct($message, $code = 0, $connectionerror = false)
    {
        $this->connectionerror = $connectionerror;
        parent::__construct($message, $code);
    }

    public function isConnectionError()
    {
        return ($this->connectionerror);
    }
}

abstract class tscraper
{
    protected $timeout;

    public function __construct($timeout = 2)
    {
        $this->timeout = $timeout;
    }
}

class udptscraper extends tscraper
{
    public function scrape($url, $infohash)
    {
        if (!is_array($infohash)) {
            $infohash = array(
                $infohash
            );
        }
        foreach ($infohash as $hash) {
            if (!preg_match('#^[a-f0-9]{40}$#i', $hash)) {
                throw new ScraperException('Invalid infohash: '.$hash.'.');
            }
        }
        if (count($infohash) > 74) {
            throw new ScraperException('Too many infohashes provided.');
        }
        if (!preg_match('%udp://([^:/]*)(?::([0-9]*))?(?:/)?%si', $url, $m)) {
            throw new ScraperException('Invalid tracker url.');
        }
        $tracker = 'udp://'.$m[1];
        $port = isset($m[2]) ? $m[2] : 80;
        $transaction_id = mt_rand(0, 65535);
        $fp = fsockopen($tracker, $port, $errno, $errstr);
        if (!$fp) {
            throw new ScraperException('Could not open UDP connection: '.$errno.' - '.$errstr, 0,
                true);
        }
        stream_set_timeout($fp, $this->timeout);
//        stream_set_blocking($fp,f);
        $current_connid = "\x00\x00\x04\x17\x27\x10\x19\x80";
        $packet = $current_connid.pack("N", 0).pack("N", $transaction_id);
        fwrite($fp, $packet);
        $ret = fread($fp, 16);
        if (strlen($ret) < 1) {
            throw new ScraperException('No connection response.', 0, true);
        }
        if (strlen($ret) < 16) {
            throw new ScraperException('Too short connection response.');
        }
        $retd = unpack("Naction/Ntransid", $ret);
        if ($retd['action'] != 0 || $retd['transid'] != $transaction_id) {
            throw new ScraperException('Invalid connection response.');
        }
        $current_connid = substr($ret, 8, 8);
        $hashes = '';
        foreach ($infohash as $hash) {
            $hashes .= pack('H*', $hash);
        }
        $packet = $current_connid.pack("N", 2).pack("N", $transaction_id).$hashes;
        fwrite($fp, $packet);
        $readlength = 8 + (12 * count($infohash));
        $ret = fread($fp, $readlength);
        if (strlen($ret) < 1) {
            throw new ScraperException('No scrape response.', 0, true);
        }
        if (strlen($ret) < 8) {
            throw new ScraperException('Too short scrape response.');
        }
        $retd = unpack("Naction/Ntransid", $ret);
        if ($retd['action'] != 2 || $retd['transid'] != $transaction_id) {
            throw new ScraperException('Invalid scrape response.');
        }
        if (strlen($ret) < $readlength) {
            throw new ScraperException('Too short scrape response.');
        }
        $torrents = array();
        $index = 8;
        foreach ($infohash as $hash) {
            $retd = unpack("Nseeders/Ncompleted/Nleechers", substr($ret, $index, 12));
            $retd['infohash'] = $hash;
            $torrents[$hash] = $retd;
            $index = $index + 12;
        }
        return ($torrents);
    }
}

try {
    $timeout = 22;
    $scraper = new udptscraper($timeout);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ret = $scraper->scrape($row['announce'], $row['info_hash']);
    }
    foreach ($ret as $key => $value) {
        $stmt = $conn->prepare("UPDATE torrents SET leechers='".$value['leechers']."', seeders='".$value['seeders']."', times_completed='".$value['completed']."', last_action='".get_date_time()."', visible='yes' WHERE id = :torrentid");
        $stmt->bindValue(':torrentid', $torrentid, PDO::PARAM_INT);
        $stmt->execute();
    }
} catch (ScraperException $e) {
    echo('Error: '.$e->getMessage()."<br />\n");
    echo('Connection error: '.($e->isConnectionError() ? 'yes' : 'no')."<br />\n");
}
?>


