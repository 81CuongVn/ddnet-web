<?php
setlocale(LC_CTYPE, "en_US.UTF-8");
require './.config/config.php';

$token = $config['token'];
$twdir = $config['twdir'];
$banfile = $twdir.$config['banfile'];

function e_handler($errno, $errstr, $errfile, $errline, $errcontext = null) {
    $bt = debug_backtrace();
    $bt_json = json_encode($bt);
    $fname = "/tmp/php.fatal.". time(). ".log";

    $f = fopen($fname, "c");
    if(!$f) {
        error_log("couldn't open btfile");
        return false;
    }

    fwrite($f, $bt_json);
    fwrite($f, "\n");
    fclose($f);
    error_log("See $fname ");

    return false;
}
set_error_handler('e_handler', E_ERROR | E_USER_ERROR);

if(empty($_SERVER['HTTP_X_DDNET_TOKEN']) || $_SERVER['HTTP_X_DDNET_TOKEN'] !== $token) {
    http_response_code(401);
    die("Unauthorized");
}

function escapetw($str) {
    $new = str_replace('\\', '\\\\', $str);
    return str_replace('"', '\\"', $new);
}

class NotFound extends Exception {}
class BadRequest extends Exception {}
class InternalServerError extends Exception {}

class Ban {
    protected $ip = "";
    protected $reason = "";
    protected $note = "";

    public function __construct($ip, $reason, $note) {
        $this->ip = $ip;
        $this->reason = $reason;
        $this->note = $note;
    }

    public function stringify() {
        return sprintf("ban %s -1 \"%s\" # %s", $this->ip, escapetw($this->reason), $this->note);
    }

    public function get_ip() {
        return $this->ip;
    }
}

class RegionalBan extends Ban {
    protected $region = "";

    public function __construct($ip, $reason, $note, $region) {
        parent::__construct($ip, $reason, $note);
        $this->region = $region;
    }

    public function stringify() {
        return sprintf("ban_region %s %s -1 \"%s\" # %s", $this->region, $this->ip, escapetw($this->reason), $this->note);
    }
}

class BanRange extends Ban {
    protected $endip = "";

    public function __construct($startip, $endip, $reason, $note) {
        if(ip2long($startip) > ip2long($endip))
            throw new Exception();

        parent::__construct($startip, $reason, $note);
        $this->endip = $endip;
    }

    public function stringify() {
        return sprintf("ban_range %s %s -1 \"%s\" # %s", $this->ip, $this->endip, escapetw($this->reason), $this->note);
    }

    public function get_ip() {
        return [$this->ip, $this->endip];
    }
}

class RegionalRangeBan extends BanRange {
  private $region = "";

  public function __construct($startip, $endip, $reason, $note, $region) {
      parent::__construct($startip, $endip, $reason, $note);
      $this->region = $region;
  }

  public function stringify() {
      return sprintf("ban_region_range %s %s %s -1 \"%s\" # %s", $this->region, $this->ip, $this->endip, escapetw($this->reason), $this->note);
  }
}

if(empty($_GET['ip'])) {
    http_response_code(400);
    die("Bad Request");
}

$ip = $_GET['ip'];
$range = false;
if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
    $parts = explode('-', $ip);
    if(count($parts) != 2 || !filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) ||
        !filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) ||
        ip2long($parts[0]) > ip2long($parts[1]))
    {
        http_response_code(400);
        die("Bad Request: Malformed ip address");
    }

    $range = true;
    $ip = $parts;
}

function propagate($run = NULL) {
    global $twdir;
    $rc = 0;

    if($run)
        exec("sudo -n -u teeworlds ". $twdir. "execute-all.sh ". escapeshellarg($run). " > /dev/null 2>&1 &", $out, $rc);

    return $rc;
}

//Only call after you have at least a shared lock
function get_bans($file) {
    $lines = explode("\n", stream_get_contents($file));
    $lines = array_filter($lines, function ($line) {
        if(empty($line) || $line[0] == '#')
            return false;
        if(!strncmp($line, "ban", 3) || !strncmp($line, "ban_range", 9))
            return true;

        return false;
    });

    $bans = array_map(function($line) {
        if(preg_match('/^ban ([\d.]+) -1 "(.*)" # (.*)$/', $line, $matches) === 1) {
            return new Ban($matches[1], $matches[2], $matches[3]);
        }
        else if(preg_match('/^ban_region ([a-zA-Z]{3}) ([\d.]+) -1 "(.*)" # (.*)$/', $line, $matches) === 1) {
            return new RegionalBan($matches[2], $matches[3], $matches[4], $matches[1]);
        }
        else if(preg_match('/^ban_range ([\d.]+) ([\d.]+) -1 "(.*)" # (.*)$/', $line, $matches) === 1) {
            return new BanRange($matches[1], $matches[2], $matches[3], $matches[4]);
        }
        else if(preg_match('/^ban_region_range ([a-zA-Z]{3}) ([\d.]+) ([\d.]+) -1 "(.*)" # (.*)$/', $line, $matches) === 1) {
            return new RegionalRangeBan($matches[2], $matches[3], $matches[4], $matches[5], $matches[1]);
        }

        return NULL;
    }, $lines);

    return array_values(array_filter($bans));
}

function write_bans($file, &$bans) {
    ftruncate($file, 0);
    fseek($file, 0);
    fwrite($file, "#". time(). "\n");
    fwrite($file, "#this file is generated by a script, DO NOT EDIT THE LINE ABOVE\n");
    foreach($bans as $ban)
        fwrite($file, $ban->stringify(). "\n");
    fwrite($file, "\n");
}

$file = NULL;
try {
    if($_SERVER['REQUEST_METHOD'] === "GET") {
        $file = fopen($banfile, "r");
        if(!$file)
            throw new InternalServerError();

        if(flock($file, LOCK_SH)) {
            $bans = get_bans($file);
            flock($file, LOCK_UN);
            fclose($file);

            foreach($bans as $ban) {
                if($ip === $ban->get_ip()) {
                    http_response_code(200);
                    die("Found: ". $ban->stringify());
                }
            }

            throw new NotFound();
        }
        else
            throw new InternalServerError();
    }
    else if($_SERVER['REQUEST_METHOD'] === "POST") {
        if(empty($_GET['reason']))
            throw new BadRequest();

        $note = empty($_GET['note']) ? "" : $_GET['note'];

        $file = fopen($banfile, "c+");
        if(!$file)
            throw new InternalServerError();

        if(flock($file, LOCK_EX)) {
            $bans = get_bans($file);
            foreach($bans as $ban) {
                if($ip === $ban->get_ip()) {
                    http_response_code(409);
                    die("Already added");
                }
            }

            try {
                #error_log("Adding: ". json_encode($_GET));
                $new = NULL;
                if($range) {
                    if(empty($_GET['region']))
                        $new = new BanRange($ip[0], $ip[1], $_GET['reason'], $note);
                    else
                        $new = new RegionalRangeBan($ip[0], $ip[1], $_GET['reason'], $note, $_GET['region']);
                }
                else if(!empty($_GET['region']))
                    $new = new RegionalBan($ip, $_GET['reason'], $note, $_GET['region']);
                else
                    $new = new Ban($ip, $_GET['reason'], $note);
            }
            catch(Exception $e) {
                throw new InternalServerError();
            }

            $bans[] = $new;
            write_bans($file, $bans);
            flock($file, LOCK_UN);
            fclose($file);

            http_response_code(201);
            if(propagate($new->stringify()))
                error_log("Error while propagating");
            die("Added");
        }
        else
            throw new InternalServerError();
    }
    else if($_SERVER['REQUEST_METHOD'] === "DELETE") {
        $file = fopen($banfile, "c+");
        if(!$file)
            throw new InternalServerError();

        if(flock($file, LOCK_EX)) {
            $bans = get_bans($file);
            $found = -1;
            foreach($bans as $idx=>$ban) {
                if($ip === $ban->get_ip()) {
                    $found = $idx;
                    break;
                }
            }
            if($found == -1)
                throw new NotFound();

            $rem = $bans[$idx];
            array_splice($bans, $idx, 1);
            write_bans($file, $bans);
            flock($file, LOCK_UN);
            fclose($file);

            if($range)
                $run = sprintf("unban_range %s %s", $rem->get_ip()[0], $rem->get_ip()[1]);
            else
                $run = sprintf("unban %s", $rem->get_ip());

            http_response_code(200);
            if(propagate($run))
                error_log("Error while propagating");
            die("Removed");
        }
        else
            throw new InternalServerError();
    }
    else
        throw new BadRequest();
}
catch(BadRequest $e) {
    http_response_code(400);
    die("Bad request");
}
catch(NotFound $e) {
    http_response_code(404);
    die("Not found");
}
catch(Exception $e) {
    http_response_code(500);
    die("Internal Server Error");
}
finally {
    if($file)
    {
        flock($file, LOCK_UN);
        fclose($file);
    }
}
?>
