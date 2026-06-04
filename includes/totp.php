<?php
/*
 * Minimal TOTP Implementation (RFC 6238)
 */

function totp_generate_secret($length = 16) {
    $b32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $s = "";
    for ($i = 0; $i < $length; $i++) {
        $s .= $b32[random_int(0, 31)];
    }
    return $s;
}

function base32_decode($b32) {
    $b32 = strtoupper($b32);
    $b32 = str_replace('=', '', $b32);
    $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $bin = "";
    for ($i = 0; $i < strlen($b32); $i++) {
        $bin .= str_pad(decbin(strpos($chars, $b32[$i])), 5, '0', STR_PAD_LEFT);
    }
    $bin = str_split($bin, 8);
    $res = "";
    foreach ($bin as $b) {
        if (strlen($b) == 8) {
            $res .= chr(bindec($b));
        }
    }
    return $res;
}

function totp_get_code($secret, $time_slice = null) {
    if ($time_slice === null) {
        $time_slice = floor(time() / 30);
    }
    $secretKey = base32_decode($secret);
    
    $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $time_slice);
    $hmac = hash_hmac('sha1', $time, $secretKey, true);
    
    $offset = ord(substr($hmac, -1)) & 0x0F;
    $hashPart = substr($hmac, $offset, 4);
    
    $value = unpack('N', $hashPart);
    $value = $value[1] & 0x7FFFFFFF;
    
    return str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
}

function totp_verify($secret, $code, $window = 1) {
    $time_slice = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (totp_get_code($secret, $time_slice + $i) === $code) {
            return true;
        }
    }
    return false;
}

function totp_provisioning_uri($secret, $name, $issuer) {
    return "otpauth://totp/" . rawurlencode($issuer . ":" . $name) . "?secret=" . $secret . "&issuer=" . rawurlencode($issuer);
}
