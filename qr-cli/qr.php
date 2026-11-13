<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the terminal.\n");
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Missing dependency. Run:  composer require chillerlan/php-qrcode\n");
    exit(1);
}
require $autoload;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;

const CONFIG_FILE_NAME = '.qrcli.json';

// ============ config ============
function configPath(): string
{
    $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
    return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . CONFIG_FILE_NAME;
}

function loadConfig(): array
{
    $defaults = [
        'url'     => '',
        'ecc'     => 'M',
        'scale'   => 10,
        'out'     => getcwd() ?: '.',
        'fg'      => '#000000',
        'bg'      => '#ffffff',
        'logo'    => '',
        'history' => [],
    ];
    $file = configPath();
    if (is_file($file)) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data)) {
            return array_merge($defaults, $data);
        }
    }
    return $defaults;
}

function saveConfig(array $cfg): void
{
    @file_put_contents(configPath(), json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ============ helpers ============
function color(string $text, string $code): string
{
    return stream_isatty(STDOUT) ? "\033[{$code}m{$text}\033[0m" : $text;
}

function ask(string $prompt, string $default = ''): string
{
    $suffix = $default !== '' ? color(" [$default]", '90') : '';
    echo $prompt . $suffix . ': ';
    $line = fgets(STDIN);
    if ($line === false) {
        exit(0);
    }
    $line = trim($line);
    return $line === '' ? $default : $line;
}

function normalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

// Works with both library v5 (int constants) and v6 (enum cases)
function eccConst(string $level)
{
    $name = strtoupper($level);
    if (!in_array($name, ['L', 'M', 'Q', 'H'], true)) {
        $name = 'M';
    }
    return constant(EccLevel::class . '::' . $name);
}

function slugify(string $text, int $max = 60): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
    $slug = trim($slug, '-');
    return substr($slug, 0, $max) ?: 'qrcode';
}

function nameFromUrl(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST) ?: $url;
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    return slugify($host . $path);
}

// '#abc', 'abc', '#aabbcc', 'aabbcc' -> '#aabbcc' (or null if invalid)
function normalizeHex(string $hex): ?string
{
    $hex = ltrim(trim($hex), '#');
    if (preg_match('/^[0-9a-f]{3}$/i', $hex)) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return preg_match('/^[0-9a-f]{6}$/i', $hex) ? '#' . strtolower($hex) : null;
}

function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function luminance(string $hex): float
{
    [$r, $g, $b] = hexToRgb($hex);
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function wifiEscape(string $s): string
{
    return preg_replace('/([\\\\;,:"])/', '\\\\$1', $s);
}

function wifiPayload(string $ssid, string $pass, string $enc, bool $hidden): string
{
    $enc = strtoupper($enc);
    if (!in_array($enc, ['WPA', 'WEP', 'NOPASS'], true)) {
        $enc = 'WPA';
    }
    $type = $enc === 'NOPASS' ? 'nopass' : $enc;
    $out = 'WIFI:T:' . $type . ';S:' . wifiEscape($ssid) . ';';
    if ($type !== 'nopass') {
        $out .= 'P:' . wifiEscape($pass) . ';';
    }
    if ($hidden) {
        $out .= 'H:true;';
    }
    return $out . ';';
}

// Style = ['fg' => '#000000', 'bg' => '#ffffff', 'logo' => 'path or empty']
function styleFrom(array $cfg): array
{
    $fg = normalizeHex((string) $cfg['fg']) ?? '#000000';
    $bg = normalizeHex((string) $cfg['bg']) ?? '#ffffff';
    $logo = (string) $cfg['logo'];
    if ($logo !== '' && !is_file($logo)) {
        fwrite(STDERR, color("Logo file not found, ignoring: $logo\n", '33'));
        $logo = '';
    }
    if (luminance($fg) >= luminance($bg)) {
        fwrite(STDERR, color("Warning: light code on a dark background may not scan on some phones.\n", '33'));
    }
    return ['fg' => $fg, 'bg' => $bg, 'logo' => $logo];
}

// ============ QR matrix ============
function buildMatrix(string $data, string $ecc)
{
    $qr = new QRCode(new QROptions(['eccLevel' => eccConst($ecc)]));

    if (method_exists($qr, 'getMatrix')) {
        return $qr->getMatrix($data);        // library v5
    }
    $qr->addByteSegment($data);              // library v6
    return $qr->getQRMatrix();
}

function renderTerminal(string $data, string $ecc, string $label = ''): void
{
    $matrix = buildMatrix($data, $ecc);
    $size = $matrix->getSize();
    $quiet = 2;
    $total = $size + $quiet * 2;

    $dark = function (int $x, int $y) use ($matrix, $size, $quiet): bool {
        $x -= $quiet;
        $y -= $quiet;
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return false;
        }
        return (bool) $matrix->check($x, $y);
    };

    $on = "\033[47;30m";
    $off = "\033[0m";

    echo "\n";
    for ($y = 0; $y < $total; $y += 2) {
        $line = '';
        for ($x = 0; $x < $total; $x++) {
            $top = $dark($x, $y);
            $bottom = $dark($x, $y + 1);
            $line .= match (true) {
                $top && $bottom => '█',
                $top            => '▀',
                $bottom         => '▄',
                default         => ' ',
            };
        }
        echo '  ' . $on . $line . $off . "\n";
    }
    $label = $label !== '' ? $label : $data;
    echo "\n  " . color($label, '36') . color("  (ECC $ecc, {$size}x{$size} modules)", '90') . "\n\n";
}

// ============ file output ============
function logoBox(string $path, int $boxSize): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $info = @getimagesizefromstring($raw);
    if (!$info) {
        return null;
    }
    [$w, $h] = $info;
    $ratio = min($boxSize / $w, $boxSize / $h);
    return [
        'raw'  => $raw,
        'mime' => $info['mime'],
        'w'    => max(1, (int) round($w * $ratio)),
        'h'    => max(1, (int) round($h * $ratio)),
    ];
}

function makeSvg($matrix, int $scale, array $style): string
{
    $size = $matrix->getSize();
    $quiet = 4;
    $total = ($size + $quiet * 2) * $scale;

    $rects = '';
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($matrix->check($x, $y)) {
                $px = ($x + $quiet) * $scale;
                $py = ($y + $quiet) * $scale;
                $rects .= "<rect x=\"$px\" y=\"$py\" width=\"$scale\" height=\"$scale\"/>";
            }
        }
    }

    $logoSvg = '';
    if ($style['logo'] !== '') {
        $box = (int) round($total * 0.2);
        $logo = logoBox($style['logo'], $box);
        if ($logo) {
            $pad = max(2, (int) round($scale * 1.5));
            $lx = intdiv($total - $logo['w'], 2);
            $ly = intdiv($total - $logo['h'], 2);
            $b64 = base64_encode($logo['raw']);
            $logoSvg = '<rect x="' . ($lx - $pad) . '" y="' . ($ly - $pad) . '" width="' . ($logo['w'] + 2 * $pad)
                . '" height="' . ($logo['h'] + 2 * $pad) . '" fill="' . $style['bg'] . '"/>'
                . '<image x="' . $lx . '" y="' . $ly . '" width="' . $logo['w'] . '" height="' . $logo['h']
                . '" href="data:' . $logo['mime'] . ';base64,' . $b64 . '"/>';
        }
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$total\" height=\"$total\" viewBox=\"0 0 $total $total\">"
        . "<rect width=\"100%\" height=\"100%\" fill=\"{$style['bg']}\"/>"
        . "<g fill=\"{$style['fg']}\">$rects</g>"
        . $logoSvg
        . "</svg>\n";
}

function makePng($matrix, int $scale, array $style): string
{
    $size = $matrix->getSize();
    $quiet = 4;
    $total = ($size + $quiet * 2) * $scale;

    $img = imagecreatetruecolor($total, $total);
    [$br, $bg_, $bb] = hexToRgb($style['bg']);
    [$fr, $fg_, $fb] = hexToRgb($style['fg']);
    $bgCol = imagecolorallocate($img, $br, $bg_, $bb);
    $fgCol = imagecolorallocate($img, $fr, $fg_, $fb);
    imagefilledrectangle($img, 0, 0, $total, $total, $bgCol);

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($matrix->check($x, $y)) {
                $px = ($x + $quiet) * $scale;
                $py = ($y + $quiet) * $scale;
                imagefilledrectangle($img, $px, $py, $px + $scale - 1, $py + $scale - 1, $fgCol);
            }
        }
    }

    if ($style['logo'] !== '') {
        $box = (int) round($total * 0.2);
        $logo = logoBox($style['logo'], $box);
        $src = $logo ? @imagecreatefromstring($logo['raw']) : false;
        if ($logo && $src) {
            $pad = max(2, (int) round($scale * 1.5));
            $lx = intdiv($total - $logo['w'], 2);
            $ly = intdiv($total - $logo['h'], 2);
            imagefilledrectangle(
                $img,
                $lx - $pad,
                $ly - $pad,
                $lx + $logo['w'] + $pad - 1,
                $ly + $logo['h'] + $pad - 1,
                $bgCol
            );
            imagecopyresampled($img, $src, $lx, $ly, 0, 0, $logo['w'], $logo['h'], imagesx($src), imagesy($src));
            imagedestroy($src);
        } else {
            fwrite(STDERR, color("Could not read the logo image (use PNG/JPG/GIF). Saved without logo.\n", '33'));
        }
    }

    ob_start();
    imagepng($img);
    $data = (string) ob_get_clean();
    imagedestroy($img);
    return $data;
}

function saveFile(string $data, string $name, string $format, string $dir, string $ecc, int $scale, array $style): ?string
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        fwrite(STDERR, color("Cannot create folder: $dir\n", '31'));
        return null;
    }

    if ($format === 'png' && !extension_loaded('gd')) {
        fwrite(STDERR, color("PNG needs the PHP GD extension. Enable 'extension=gd' in php.ini, or use SVG.\n", '31'));
        return null;
    }

    try {
        // A logo covers part of the code, so use the highest error correction
        $useEcc = $style['logo'] !== '' ? 'H' : $ecc;
        $matrix = buildMatrix($data, $useEcc);
        $scale = max(1, $scale);
        $out = $format === 'svg' ? makeSvg($matrix, $scale, $style) : makePng($matrix, $scale, $style);
    } catch (Throwable $e) {
        fwrite(STDERR, color('Failed: ' . $e->getMessage() . "\n", '31'));
        return null;
    }

    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . '-qr.' . $format;
    if (file_put_contents($path, $out) === false) {
        fwrite(STDERR, color("Could not write $path\n", '31'));
        return null;
    }
    echo color("Saved: ", '32') . realpath($path) . "\n";
    return realpath($path) ?: $path;
}

function saveFormats(string $data, string $name, array $formats, array $cfg, array $style): ?string
{
    $last = null;
    foreach ($formats as $fmt) {
        $last = saveFile($data, $name, $fmt, (string) $cfg['out'], (string) $cfg['ecc'], (int) $cfg['scale'], $style) ?? $last;
    }
    return $last;
}

// Batch: one link per line in a text file ('#' starts a comment)
function runBatch(string $file, array $formats, array $cfg, array $style): ?string
{
    if (!is_file($file)) {
        fwrite(STDERR, color("List file not found: $file\n", '31'));
        return null;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $seen = [];
    $count = 0;
    $last = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $url = normalizeUrl($line);
        $name = nameFromUrl($url);
        $seen[$name] = ($seen[$name] ?? 0) + 1;
        if ($seen[$name] > 1) {
            $name .= '-' . $seen[$name];
        }
        echo color("[" . ++$count . "] ", '90') . $url . "\n";
        $last = saveFormats($url, $name, $formats, $cfg, $style) ?? $last;
    }
    echo color("\nDone: $count link(s) processed.\n", '32');
    return $last;
}

function openFile(string $path): void
{
    $cmd = match (PHP_OS_FAMILY) {
        'Darwin'  => 'open ' . escapeshellarg($path),
        'Windows' => 'start "" ' . escapeshellarg($path),
        default   => 'xdg-open ' . escapeshellarg($path) . ' >/dev/null 2>&1 &',
    };
    exec($cmd);
}

function copyToClipboard(string $text): bool
{
    $candidates = match (PHP_OS_FAMILY) {
        'Darwin'  => ['pbcopy'],
        'Windows' => ['clip'],
        default   => ['wl-copy', 'xclip -selection clipboard', 'xsel --clipboard --input'],
    };
    foreach ($candidates as $cmd) {
        $bin = explode(' ', $cmd)[0];
        $exists = PHP_OS_FAMILY === 'Windows'
            ? trim((string) shell_exec("where $bin 2>nul")) !== ''
            : trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) !== '';
        if ($exists) {
            $p = proc_open($cmd, [0 => ['pipe', 'r']], $pipes);
            if (is_resource($p)) {
                fwrite($pipes[0], $text);
                fclose($pipes[0]);
                proc_close($p);
                return true;
            }
        }
    }
    return false;
}

function addHistory(array &$cfg, string $url): void
{
    $cfg['history'] = array_values(array_unique(array_merge([$url], $cfg['history'])));
    $cfg['history'] = array_slice($cfg['history'], 0, 10);
    $cfg['url'] = $url;
}

function formatsFromAnswer(string $answer): array
{
    return match (strtolower($answer)) {
        'png'  => ['png'],
        'svg'  => ['svg'],
        'both' => ['png', 'svg'],
        default => [],
    };
}

// ============ CLI args ============
function parseArgs(array $argv): array
{
    $opts = [
        'url' => null, 'png' => false, 'svg' => false, 'open' => false,
        'help' => false, 'hidden' => false,
    ];
    $valueOpts = ['out', 'ecc', 'scale', 'fg', 'bg', 'logo', 'file', 'wifi', 'pass', 'enc'];
    $a = array_slice($argv, 1);

    for ($i = 0; $i < count($a); $i++) {
        $arg = $a[$i];
        if ($arg === '--png') {
            $opts['png'] = true;
        } elseif ($arg === '--svg') {
            $opts['svg'] = true;
        } elseif ($arg === '--open') {
            $opts['open'] = true;
        } elseif ($arg === '--hidden') {
            $opts['hidden'] = true;
        } elseif ($arg === '-h' || $arg === '--help') {
            $opts['help'] = true;
        } elseif (str_starts_with($arg, '--')) {
            $kv = explode('=', substr($arg, 2), 2);
            if (in_array($kv[0], $valueOpts, true)) {
                if (count($kv) === 2) {
                    $opts[$kv[0]] = $kv[1];
                } else {
                    $opts[$kv[0]] = $a[$i + 1] ?? '';
                    $i++;
                }
            }
        } elseif (!str_starts_with($arg, '-')) {
            $opts['url'] = $arg;
        }
    }
    return $opts;
}

function usage(): void
{
    echo <<<TXT

QR CLI
  ma-qr                          interactive menu
  ma-qr <url> [options]          link QR code
  ma-qr --wifi "Name" --pass "secret" [--enc WPA|WEP|nopass] [--hidden]
  ma-qr --file links.txt --png   batch: one link per line

Options
  --png / --svg    save as PNG (needs GD) / SVG
  --out=<folder>   save folder
  --ecc=L|M|Q|H    error correction (default M; a logo forces H)
  --scale=<n>      pixel size per module (default 10)
  --fg=<hex>       code color, e.g. --fg=#0a3d91
  --bg=<hex>       background color
  --logo=<image>   logo in the middle (PNG/JPG/GIF)
  --open           open the last saved file
  -h, --help       this help

TXT;
}

// ============ main ============
$cfg = loadConfig();
$args = parseArgs($argv);

if ($args['help']) {
    usage();
    exit(0);
}

// Command-line mode: link, wifi or batch file given
if ($args['url'] !== null || isset($args['wifi']) || isset($args['file'])) {
    $run = $cfg;
    foreach (['out', 'ecc', 'fg', 'bg', 'logo'] as $k) {
        if (isset($args[$k]) && $args[$k] !== '') {
            $run[$k] = $k === 'ecc' ? strtoupper($args[$k]) : $args[$k];
        }
    }
    if (isset($args['scale'])) {
        $run['scale'] = max(1, (int) $args['scale']);
    }
    $style = styleFrom($run);
    $formats = array_values(array_filter(['png', 'svg'], fn ($f) => $args[$f]));
    $last = null;

    if (isset($args['file'])) {
        if (!$formats) {
            $formats = ['png'];
        }
        $last = runBatch((string) $args['file'], $formats, $run, $style);
    } elseif (isset($args['wifi'])) {
        $data = wifiPayload((string) $args['wifi'], (string) ($args['pass'] ?? ''), (string) ($args['enc'] ?? 'WPA'), (bool) $args['hidden']);
        renderTerminal($data, (string) $run['ecc'], 'WiFi: ' . $args['wifi']);
        $last = saveFormats($data, 'wifi-' . slugify((string) $args['wifi'], 40), $formats, $run, $style);
    } else {
        $url = normalizeUrl((string) $args['url']);
        renderTerminal($url, (string) $run['ecc']);
        $last = saveFormats($url, nameFromUrl($url), $formats, $run, $style);
        addHistory($cfg, $url);
        saveConfig($cfg);
    }

    if ($args['open'] && $last) {
        openFile($last);
    }
    exit(0);
}

// Interactive mode
$lastSaved = null;
if ($cfg['url'] === '') {
    $cfg['url'] = normalizeUrl(ask('Enter your website URL'));
}

while (true) {
    $logoLabel = $cfg['logo'] !== '' ? basename((string) $cfg['logo']) : 'none';
    echo color("\n=== QR CLI ===", '1;35') . "  " . color($cfg['url'] ?: '(no URL set)', '36') . "\n";
    echo " 1) Show QR in terminal\n";
    echo " 2) Save as PNG\n";
    echo " 3) Save as SVG\n";
    echo " 4) Change URL / pick from history\n";
    echo " 5) Settings (ECC {$cfg['ecc']}, scale {$cfg['scale']}, colors {$cfg['fg']} on {$cfg['bg']}, logo $logoLabel)\n";
    echo " 6) Copy URL to clipboard\n";
    echo " 7) Open last saved file\n";
    echo " 8) WiFi QR code\n";
    echo " 9) Batch: many links from a text file\n";
    echo " q) Quit\n";
    $choice = strtolower(ask('Choose', '1'));

    switch ($choice) {
        case '1':
            if ($cfg['url'] === '') { echo "Set a URL first (option 4).\n"; break; }
            renderTerminal($cfg['url'], (string) $cfg['ecc']);
            break;

        case '2':
        case '3':
            if ($cfg['url'] === '') { echo "Set a URL first (option 4).\n"; break; }
            $fmt = $choice === '2' ? 'png' : 'svg';
            $style = styleFrom($cfg);
            $lastSaved = saveFormats($cfg['url'], nameFromUrl($cfg['url']), [$fmt], $cfg, $style) ?? $lastSaved;
            break;

        case '4':
            if ($cfg['history']) {
                echo "History:\n";
                foreach ($cfg['history'] as $i => $h) {
                    echo '  ' . ($i + 1) . ") $h\n";
                }
            }
            $in = ask('New URL (or history number)', (string) $cfg['url']);
            if (ctype_digit($in) && isset($cfg['history'][(int) $in - 1])) {
                $in = $cfg['history'][(int) $in - 1];
            }
            $cfg['url'] = normalizeUrl($in);
            addHistory($cfg, $cfg['url']);
            saveConfig($cfg);
            break;

        case '5':
            $ecc = strtoupper(ask('Error correction L/M/Q/H', (string) $cfg['ecc']));
            $cfg['ecc'] = in_array($ecc, ['L', 'M', 'Q', 'H'], true) ? $ecc : $cfg['ecc'];
            $cfg['scale'] = max(1, (int) ask('Pixel scale per module', (string) $cfg['scale']));
            $cfg['out'] = ask('Save folder', (string) $cfg['out']);

            $fg = normalizeHex(ask('Code color (hex)', (string) $cfg['fg']));
            if ($fg === null) { echo color("Invalid color, kept the old one.\n", '33'); } else { $cfg['fg'] = $fg; }
            $bg = normalizeHex(ask('Background color (hex)', (string) $cfg['bg']));
            if ($bg === null) { echo color("Invalid color, kept the old one.\n", '33'); } else { $cfg['bg'] = $bg; }

            $logo = ask('Logo image path (- to remove)', (string) $cfg['logo']);
            if ($logo === '-') {
                $cfg['logo'] = '';
            } elseif ($logo !== '' && !is_file($logo)) {
                echo color("File not found, kept the old logo setting.\n", '33');
            } else {
                $cfg['logo'] = $logo;
            }
            saveConfig($cfg);
            echo color("Settings saved.\n", '32');
            break;

        case '6':
            echo copyToClipboard((string) $cfg['url'])
                ? color("Copied.\n", '32')
                : color("No clipboard tool found.\n", '33');
            break;

        case '7':
            if ($lastSaved) {
                openFile($lastSaved);
            } else {
                echo "Nothing saved yet in this session.\n";
            }
            break;

        case '8':
            $ssid = ask('WiFi name (SSID)');
            if ($ssid === '') { echo "Name is required.\n"; break; }
            $enc = strtoupper(ask('Security WPA / WEP / nopass', 'WPA'));
            $pass = $enc === 'NOPASS' ? '' : ask('WiFi password');
            $hidden = strtolower(ask('Hidden network? y/N', 'n')) === 'y';
            $data = wifiPayload($ssid, $pass, $enc, $hidden);
            renderTerminal($data, (string) $cfg['ecc'], 'WiFi: ' . $ssid);
            $formats = formatsFromAnswer(ask('Save as png / svg / both / no', 'no'));
            if ($formats) {
                $style = styleFrom($cfg);
                $lastSaved = saveFormats($data, 'wifi-' . slugify($ssid, 40), $formats, $cfg, $style) ?? $lastSaved;
            }
            break;

        case '9':
            echo "Text file with one link per line (lines starting with # are ignored).\n";
            $file = ask('Path to list file');
            if ($file === '') { break; }
            $formats = formatsFromAnswer(ask('Save as png / svg / both', 'png'));
            if (!$formats) { echo "Nothing selected.\n"; break; }
            $style = styleFrom($cfg);
            $lastSaved = runBatch($file, $formats, $cfg, $style) ?? $lastSaved;
            break;

        case 'q':
        case 'quit':
        case 'exit':
            if ($cfg['url'] !== '') {
                addHistory($cfg, (string) $cfg['url']);
            }
            saveConfig($cfg);
            echo "Bye!\n";
            exit(0);

        default:
            echo "Unknown option.\n";
    }
}