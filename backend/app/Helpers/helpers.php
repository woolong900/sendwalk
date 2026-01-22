<?php

if (!function_exists('maskEmail')) {
    /**
     * 邮箱脱敏处理
     * example@gmail.com → exa***@gmail.com
     * ab@gmail.com → a***@gmail.com
     * a@gmail.com → ***@gmail.com
     *
     * @param string|null $email
     * @return string
     */
    function maskEmail(?string $email): string
    {
        if (empty($email) || !str_contains($email, '@')) {
            return $email ?? '';
        }

        [$localPart, $domain] = explode('@', $email);

        if (strlen($localPart) <= 1) {
            return "***@{$domain}";
        } elseif (strlen($localPart) <= 3) {
            return substr($localPart, 0, 1) . "***@{$domain}";
        } else {
            return substr($localPart, 0, 3) . "***@{$domain}";
        }
    }
}
