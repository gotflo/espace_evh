<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Envoi de notifications Web Push (standard W3C) sans dependance externe :
 * - authentification VAPID (RFC 8292, jeton JWT signe ES256) ;
 * - chiffrement du message aes128gcm (RFC 8291 / RFC 8188).
 * Fonctionne avec Chrome/Android (FCM), Firefox, Edge et Safari/iPhone (app installee).
 *
 * Les cles VAPID viennent de VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY si elles sont definies,
 * sinon elles sont generees automatiquement une fois et conservees dans storage/app.
 */
class WebPush
{
    /** En-tete DER d'une cle publique P-256 non compressee (SubjectPublicKeyInfo). */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private ?array $keys = null;

    /** Cle publique VAPID (base64url) a transmettre au navigateur pour s'abonner. */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /**
     * Envoie un message a un abonnement. Retourne false si l'abonnement est expire
     * (il est alors supprime) ou en cas d'echec.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(PushSubscription $subscription, array $payload, int $ttl = 86400, string $urgency = 'normal'): bool
    {
        try {
            $body = $this->encrypt(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $subscription->public_key,
                $subscription->auth_token,
            );

            $response = Http::timeout(8)
                ->withHeaders([
                    'Authorization' => $this->vapidHeader($subscription->endpoint),
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => (string) $ttl,
                    'Urgency' => $urgency,
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (\Throwable $e) {
            Log::warning('Push : envoi impossible', ['error' => $e->getMessage()]);

            return false;
        }

        // 404 / 410 : l'abonnement n'existe plus (appli desinstallee, permission retiree...).
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Push : refuse par le service', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

            return false;
        }

        $subscription->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /** Chiffre le message pour l'appareil (aes128gcm, un seul enregistrement). */
    public function encrypt(string $plaintext, string $userPublicKey, string $userAuth): string
    {
        $uaPublic = self::b64uDecode($userPublicKey);
        $authSecret = self::b64uDecode($userAuth);
        if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) {
            throw new RuntimeException('Abonnement push invalide.');
        }

        // Cle ephemere du serveur pour ce message + secret partage ECDH.
        $ephemeral = $this->newKeyPair();
        $asPublic = $ephemeral['public_raw'];
        $shared = openssl_pkey_derive(self::publicKeyFromRaw($uaPublic), $ephemeral['key'], 32);
        if ($shared === false) {
            throw new RuntimeException('Echange de cles impossible.');
        }

        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Delimiteur 0x02 = dernier (et unique) enregistrement.
        $tag = '';
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Chiffrement impossible.');
        }

        return $salt.pack('N', 4096).chr(strlen($asPublic)).$asPublic.$cipher.$tag;
    }

    /** En-tete Authorization VAPID pour le service push de l'endpoint. */
    private function vapidHeader(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $header = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64uEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $this->subject(),
        ], JSON_UNESCAPED_SLASHES));
        $signingInput = $header.'.'.$claims;

        $keys = $this->keys();
        if (! openssl_sign($signingInput, $der, $this->privateKey($keys), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature VAPID impossible.');
        }

        $jwt = $signingInput.'.'.self::b64uEncode(self::derToRaw($der));

        return 'vapid t='.$jwt.', k='.$keys['public'];
    }

    /** Contact exige par les services push (mailto: ou URL https). */
    private function subject(): string
    {
        $subject = (string) config('services.webpush.subject');
        if ($subject !== '') {
            return $subject;
        }
        $url = (string) config('app.url');

        return str_starts_with($url, 'https://') ? $url : 'mailto:admin@vasesdhonneurchicoutimi.org';
    }

    /** @return array{public: string, private: string} */
    private function keys(): array
    {
        if ($this->keys) {
            return $this->keys;
        }

        $public = (string) config('services.webpush.public_key');
        $private = (string) config('services.webpush.private_key');
        if ($public !== '' && $private !== '') {
            return $this->keys = ['public' => $public, 'private' => $private];
        }

        $file = storage_path('app/webpush-vapid.json');
        if (File::exists($file)) {
            $stored = json_decode(File::get($file), true);
            if (! empty($stored['public']) && ! empty($stored['private'])) {
                return $this->keys = $stored;
            }
        }

        // Premiere utilisation : generation automatique d'une paire de cles VAPID.
        $pair = $this->newKeyPair();
        $this->keys = ['public' => self::b64uEncode($pair['public_raw']), 'private' => self::b64uEncode($pair['private_raw'])];
        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode($this->keys));
        @chmod($file, 0600);

        return $this->keys;
    }

    /** Genere une paire P-256. @return array{key: OpenSSLAsymmetricKey, public_raw: string, private_raw: string} */
    public static function newKeyPair(): array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'config' => resource_path('openssl/openssl.cnf'),
        ]);
        if ($key === false) {
            throw new RuntimeException('Generation de cle EC impossible.');
        }
        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'key' => $key,
            'public_raw' => "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT),
            'private_raw' => str_pad($ec['d'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    /** Reconstruit la cle privee VAPID (SEC1 DER) depuis d + point public. */
    private function privateKey(array $keys): OpenSSLAsymmetricKey
    {
        $d = self::b64uDecode($keys['private']);
        $pub = self::b64uDecode($keys['public']);
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$pub;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('Cle privee VAPID invalide.');
        }

        return $key;
    }

    public static function publicKeyFromRaw(string $raw): OpenSSLAsymmetricKey
    {
        $der = hex2bin(self::P256_SPKI_PREFIX).$raw;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Cle publique invalide.');
        }

        return $key;
    }

    /** Signature ECDSA : DER (SEQUENCE{r, s}) -> r||s (64 octets) attendu par JWT. */
    private static function derToRaw(string $der): string
    {
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7F;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }

    public static function b64uEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');

        return (string) base64_decode($data.str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
