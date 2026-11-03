<?php

declare(strict_types=1);

namespace App\Mail\Inbound;

use RuntimeException;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Lee el buzón de soporte por IMAP. La conexión va cifrada y con el certificado del servidor verificado: solo se admite
 * sin cifrar en local y en pruebas. Las credenciales salen de la configuración (variables de entorno) y nunca se escriben
 * en un registro ni en un mensaje de error. Se leen primero las cabeceras y el peso; el contenido de un correo se descarga
 * solo cuando el procesador lo pide.
 */
class ImapMailboxReader implements MailboxReader
{
    /** Cabeceras que el procesador usa; el resto se descarta. */
    private const HEADERS = ['auto-submitted', 'x-autoreply', 'x-autorespond', 'x-auto-response-suppress', 'precedence', 'list-id', 'authentication-results', 'delivered-to', 'x-original-to'];

    private ?Client $client = null;

    /**
     * @param  array{host: string|null, port: int, encryption: string|null, validate_cert: bool, username: string|null, password: string|null, folder: string}  $settings
     */
    public function __construct(private readonly array $settings) {}

    public function unread(int $limit): array
    {
        $folder = $this->connect()->getFolder($this->settings['folder']);

        if ($folder === null) {
            throw new RuntimeException('La carpeta del buzón no existe.');
        }

        $messages = $folder->query()->whereUnseen()->setFetchBody(false)->limit($limit)->get();
        $mails = [];

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $mails[] = $this->toMail($message);
            }
        }

        return $mails;
    }

    public function markSeen(InboundMail $mail): void
    {
        if ($mail->handle instanceof Message) {
            $mail->handle->setFlag('Seen');
        }
    }

    private function connect(): Client
    {
        if ($this->client !== null && $this->client->isConnected()) {
            return $this->client;
        }

        $host = $this->settings['host'];
        $encryption = $this->settings['encryption'];

        if ($host === null || $host === '') {
            throw new RuntimeException('No hay servidor IMAP configurado.');
        }

        if (($encryption === null || $encryption === '' || $encryption === 'none') && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('El buzón debe leerse por una conexión cifrada.');
        }

        $client = (new ClientManager(['options' => ['fetch' => IMAP::FT_PEEK]]))->make([
            'host' => $host,
            'port' => $this->settings['port'],
            'protocol' => 'imap',
            'encryption' => in_array($encryption, ['ssl', 'tls', 'starttls'], true) ? $encryption : false,
            'validate_cert' => $this->settings['validate_cert'],
            'username' => $this->settings['username'],
            'password' => $this->settings['password'],
            'authentication' => null,
        ]);
        $client->connect();

        return $this->client = $client;
    }

    private function toMail(Message $message): InboundMail
    {
        $raw = $message->getHeader()->raw ?? '';
        $headers = self::parseHeaders($raw);

        $from = $message->getFrom()->first();
        $address = is_object($from) && isset($from->mail) && is_string($from->mail) ? $from->mail : null;

        $size = $message->getSize();

        return new InboundMail(
            handle: $message,
            messageId: self::messageId($message, $headers),
            from: $address,
            recipients: implode(' ', array_filter([$headers['to'] ?? '', $headers['cc'] ?? '', $headers['delivered-to'] ?? '', $headers['x-original-to'] ?? ''])),
            references: trim(($headers['in-reply-to'] ?? '').' '.($headers['references'] ?? '')),
            headers: array_intersect_key($headers, array_flip(self::HEADERS)),
            size: is_int($size) ? $size : 0,
            body: fn (): InboundBody => $this->download($message),
        );
    }

    private function download(Message $message): InboundBody
    {
        $message->parseBody();

        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            if ($attachment instanceof Attachment) {
                $attachments[] = new InboundAttachment($attachment->getName(), $attachment->getContent());
            }
        }

        return new InboundBody($message->getTextBody(), $message->getHTMLBody(), $attachments);
    }

    /**
     * Las cabeceras en bruto como mapa nombre en minúsculas → valor (las líneas continuadas se unen).
     *
     * @return array<string, string>
     */
    public static function parseHeaders(string $raw): array
    {
        $unfolded = (string) preg_replace("/\r?\n[ \t]+/", ' ', $raw);
        $headers = [];

        foreach (preg_split("/\r?\n/", $unfolded) ?: [] as $line) {
            $colon = strpos($line, ':');

            if ($colon === false || $colon === 0) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            // Una cabecera repetida (por ejemplo, Delivered-To) suma sus valores.
            $headers[$name] = isset($headers[$name]) ? $headers[$name].' '.$value : $value;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function messageId(Message $message, array $headers): string
    {
        $id = trim($headers['message-id'] ?? '');

        if ($id !== '') {
            return mb_substr($id, 0, 255);
        }

        // Un correo sin identificador se reconoce por una huella de lo que sí trae, para no procesarlo dos veces.
        return 'sin-id-'.hash('sha256', ($headers['from'] ?? '').'|'.($headers['date'] ?? '').'|'.($headers['subject'] ?? '').'|'.$message->getUid());
    }
}
