<?php
// [blf-keys] Adds the BLF page's keys to desk phone configs, in place, on top of whatever
// IndexController / AccountsController apple runs now (other threads change these files too).
// Usage: php patch-blf.php <IndexController.php> <AccountsController.php> <outdir>
// Writes the patched copies to <outdir>; prints what it did. Already-patched files are copied unchanged.
[$self, $indexIn, $accountsIn, $out] = $argv + [null, null, null, null];
if (!$indexIn || !$accountsIn || !$out) { fwrite(STDERR, "usage\n"); exit(2); }

$index = file_get_contents($indexIn);
if (strpos($index, "[blf-keys]") === false) {
    $method = <<<'PHPCODE'
    /** [blf-keys] the extension's BLF page rows (BLF, Speed Dial) as programmable keys in the served config. */
    private function withPhoneKeys(int $type, string $content, $vDomains, $vUser, $mac): string
    {
        try {
            $extension = (string) $vUser->vExtensionUsers->vExtensions->extension;
            $keys = \PhoneKeys::keysFor((string) $vUser->domain_uuid, $extension);
            return \PhoneKeys::applyToConfig($this->redis, $type, $content, (string) $vDomains->domain_name, (string) $mac, $keys);
        } catch (\Throwable $e) {
            error_log("withPhoneKeys: " . $e->getMessage());
            return $content;
        }
    }


PHPCODE;
    $anchor = "    /** [desk-phone-actions] the phone's web login";
    $n = 0;
    if (strpos($index, $anchor) !== false) {
        // apple: configs pass through withPhoneWebLogin; add the keys right after it
        $index = str_replace($anchor, $method . $anchor, $index);
        $index = preg_replace_callback('/^([ \t]*)(\$\w+) = \$this->withPhoneWebLogin\(([123]), [^;\n]*\);[ \t]*$/m', function ($m) use (&$n) {
            $n++;
            return $m[0] . "\n" . $m[1] . $m[2] . ' = $this->withPhoneKeys(' . $m[3] . ', ' . $m[2] . ', $vDomains, $vUser, $macAddress); // [blf-keys]';
        }, $index);
    } else {
        // no web login step: add the keys right after each Yealink / Fanvil / Snom config is read from redis
        $end = strpos($index, "    public function whatsappAction()");
        if ($end === false) { fwrite(STDERR, "IndexController: indexAction end not found\n"); exit(1); }
        $head = substr($index, 0, $end);
        $head = preg_replace_callback('/^([ \t]*)\$content = \$this->redis->get\(\$(customProvisionKey|provisionKey)\);[ \t]*$/m', function ($m) use (&$n, $head) {
            static $from = 0;
            $at = strpos($head, $m[0], $from);
            $from = $at + 1;
            if (!preg_match_all('/if \(\$type == (\d)\)/', substr($head, 0, $at), $t) || end($t[1]) === "4") { return $m[0]; }
            $n++;
            return $m[0] . "\n" . $m[1] . '$content = $this->withPhoneKeys(' . end($t[1]) . ', (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys]';
        }, $head);
        $index = $head . $method . substr($index, $end);
    }
    if ($n < 3) { fwrite(STDERR, "IndexController: only $n served-config calls found\n"); exit(1); }
    echo "IndexController: BLF keys added to $n served configs\n";
} else {
    echo "IndexController: already has BLF keys\n";
}
if (strpos($index, "library/PhoneKeys.php") === false) {
    // [blf-keys] app/library is not autoloaded on apple (PhoneAdmin is required the same way)
    $req = "require_once __DIR__ . '/../library/PhoneKeys.php'; // [blf-keys]\n";
    $anchor = "require_once __DIR__ . '/../library/PhoneAdmin.php';";
    if (strpos($index, $anchor) !== false) {
        $index = preg_replace('/^' . preg_quote($anchor, '/') . '.*$/m', "$0\n" . rtrim($req), $index, 1);
    } else {
        $index = preg_replace('/^' . preg_quote('use Phalcon\\Mvc\\View;', '/') . '.*$/m', "$0\n" . rtrim($req), $index, 1);
    }
    if (strpos($index, "library/PhoneKeys.php") === false) { fwrite(STDERR, "IndexController: could not add require for PhoneKeys\n"); exit(1); }
    echo "IndexController: loads library/PhoneKeys.php\n";
}
if (strpos($index, "[blf-keys-gs]") === false) {
    $at = strpos($index, 'elseif ($type == 4) {');
    $get = $at === false ? false : strpos($index, '$content = $this->redis->get($provisionKey);', $at);
    if ($get === false) { fwrite(STDERR, "IndexController: Grandstream block not found\n"); exit(1); }
    $eol = strpos($index, "\n", $get);
    $lineStart = strrpos(substr($index, 0, $get), "\n") + 1;
    $indent = substr($index, $lineStart, $get - $lineStart);
    $index = substr($index, 0, $eol) . "\n" . $indent . '$content = $this->withPhoneKeys(4, (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys] [blf-keys-gs]' . substr($index, $eol);
    echo "IndexController: BLF keys added to Grandstream configs\n";
}

// [desk-features] phone Message / DND buttons, and the local endpoint behind the Agent Status keys
if (strpos($index, "[desk-features]") === false) {
    $call = 'return \PhoneKeys::applyToConfig($this->redis, $type, $content,';
    $at = strpos($index, $call);
    if ($at === false) { fwrite(STDERR, "IndexController: withPhoneKeys body not found\n"); exit(1); }
    $lineStart = strrpos(substr($index, 0, $at), "\n") + 1;
    $indent = substr($index, $lineStart, $at - $lineStart);
    $index = substr($index, 0, $at) . '$content = \PhoneKeys::applyFeatures($type, $content, $extension); // [desk-features]' . "\n" . $indent . substr($index, $at);
    $method = @file_get_contents(__DIR__ . '/desk-aux-method.php.txt');
    if (!$method) { fwrite(STDERR, "desk-aux-method.php.txt missing\n"); exit(1); }
    $anchor2 = "    /** [blf-keys] the extension's BLF page rows";
    if (strpos($index, $anchor2) === false) { fwrite(STDERR, "IndexController: withPhoneKeys not found\n"); exit(1); }
    $index = str_replace($anchor2, $method . $anchor2, $index);
    $fn = strpos($index, "public function indexAction()");
    $nr = $fn === false ? false : strpos($index, "setRenderLevel(View::LEVEL_NO_RENDER);", $fn);
    if ($nr === false) { fwrite(STDERR, "IndexController: indexAction start not found\n"); exit(1); }
    $eol = strpos($index, "\n", $nr);
    $index = substr($index, 0, $eol) . "\n" . '        if ($this->request->getQuery("rq_desk_aux") !== null) { return $this->deskAux(); } // [desk-features]' . substr($index, $eol);
    echo "IndexController: phone Message / DND buttons and desk Agent Status endpoint added\n";
}

$accounts = file_get_contents($accountsIn);
if (strpos($accounts, "[blf-keys]") === false) {
    $needle = 'if (!empty($parameters["types"]) && !empty($parameters["values"])) {';
    if (substr_count($accounts, $needle) !== 1) { fwrite(STDERR, "AccountsController: BLF save block not found once\n"); exit(1); }
    $pos = strpos($accounts, $needle);
    $lineStart = strrpos(substr($accounts, 0, $pos), "\n") + 1;
    $indent = substr($accounts, $lineStart, $pos - $lineStart);
    $block = '// [blf-keys] every BLF row removed: forget the saved rows too (before, the old keys came back)' . "\n"
        . $indent . 'if ($parameters["types"] === "" && $this->request->hasPost("types")) {' . "\n"
        . $indent . '   $oldBlf = QBlfs::findFirst(["conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:", "bind" => ["domain_uuid" => $session["domain_uuid"], "extension" => strval($vExtension->extension)], "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR]]);' . "\n"
        . $indent . '   if ($oldBlf) { $oldBlf->delete(); }' . "\n"
        . $indent . '}' . "\n" . $indent;
    $accounts = substr($accounts, 0, $pos) . $block . substr($accounts, $pos);
    echo "AccountsController: removing all BLF rows now saves\n";
} else {
    echo "AccountsController: already patched\n";
}

// [blf-keys-onerow] keep one BLF row per extension: a row left by a previous user hid every new save
if (strpos($accounts, "[blf-keys-onerow]") === false) {
    $re = '/^([ \t]*)\/\/ \[blf-keys\] every BLF row removed:.*\n[ \t]*if \(\$parameters\["types"\] === "" && \$this->request->hasPost\("types"\)\) \{\n[ \t]*\$oldBlf = QBlfs::findFirst\(.*\n[ \t]*if \(\$oldBlf\) \{ \$oldBlf->delete\(\); \}\n[ \t]*\}\n/m';
    $count = 0;
    $accounts = preg_replace_callback($re, function ($m) {
        $i = $m[1];
        return $i . '// [blf-keys] [blf-keys-onerow] the page, the apps and desk phones read the extension\'s BLF row by extension only, so' . "\n"
            . $i . '// keep one row: drop rows left by the extension\'s previous users, and all rows when every BLF row is removed' . "\n"
            . $i . 'if ($this->request->hasPost("types")) {' . "\n"
            . $i . '   foreach (QBlfs::find(["conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:", "bind" => ["domain_uuid" => $session["domain_uuid"], "extension" => strval($vExtension->extension)], "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR]]) as $oldBlf) {' . "\n"
            . $i . '      if ($parameters["types"] === "" || strval($oldBlf->user_uuid) !== strval($vUser->user_uuid)) { $oldBlf->delete(); }' . "\n"
            . $i . '   }' . "\n"
            . $i . '}' . "\n";
    }, $accounts, 1, $count);
    if ($count !== 1) { fwrite(STDERR, "AccountsController: BLF cleanup block not found\n"); exit(1); }
    echo "AccountsController: keeps one BLF row per extension\n";
}

@mkdir($out, 0700, true);
file_put_contents("$out/IndexController.php", $index);
file_put_contents("$out/AccountsController.php", $accounts);
