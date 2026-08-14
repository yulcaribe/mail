<?php
declare(strict_types=1);

final class WbxmlNode
{
    public string $name;
    public string $text = '';
    public string $binary = '';

    /** @var list<WbxmlNode> */
    public array $children = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

final class EasClient
{
    private const TAGS = [
        0 => [
            5 => 'Sync', 6 => 'Responses', 7 => 'Add', 8 => 'Change', 9 => 'Delete',
            10 => 'Fetch', 11 => 'SyncKey', 12 => 'ClientId', 13 => 'ServerId',
            14 => 'Status', 15 => 'Collection', 16 => 'Class', 18 => 'CollectionId',
            19 => 'GetChanges', 20 => 'MoreAvailable', 21 => 'WindowSize',
            22 => 'Commands', 23 => 'Options', 24 => 'FilterType', 28 => 'Collections',
            29 => 'ApplicationData', 30 => 'DeletesAsMoves', 34 => 'MIMESupport',
            35 => 'MIMETruncation', 40 => 'MaxItems',
        ],
        2 => [
            5 => 'Attachment', 6 => 'Attachments', 7 => 'AttName', 8 => 'AttSize',
            9 => 'Att0Id', 10 => 'AttMethod', 12 => 'Body', 13 => 'BodySize',
            14 => 'BodyTruncated', 15 => 'DateReceived', 16 => 'DisplayName',
            17 => 'DisplayTo', 18 => 'Importance', 19 => 'MessageClass', 20 => 'Subject',
            21 => 'Read', 22 => 'To', 23 => 'Cc', 24 => 'From', 25 => 'ReplyTo',
        ],
        7 => [
            7 => 'DisplayName', 8 => 'ServerId', 9 => 'ParentId', 10 => 'Type',
            12 => 'Status', 14 => 'Changes', 15 => 'Add', 16 => 'Delete',
            17 => 'Update', 18 => 'SyncKey', 19 => 'FolderCreate', 20 => 'FolderDelete',
            21 => 'FolderUpdate', 22 => 'FolderSync', 23 => 'Count',
        ],
        17 => [
            5 => 'BodyPreference', 6 => 'Type', 7 => 'TruncationSize', 8 => 'AllOrNone',
            10 => 'Body', 11 => 'Data', 12 => 'EstimatedDataSize', 13 => 'Truncated',
            14 => 'Attachments', 15 => 'Attachment', 16 => 'DisplayName',
            17 => 'FileReference', 18 => 'Method', 19 => 'ContentId',
            20 => 'ContentLocation', 21 => 'IsInline', 22 => 'NativeBodyType',
            23 => 'ContentType', 24 => 'Preview',
        ],
    ];

    /** @var array<string, mixed> */
    private array $config;
    private string $alias;
    private string $password;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, string $username, string $password)
    {
        $this->config = $config;
        $this->alias = self::normaliseUsername($username);
        $this->password = $password;

        if ($password === '') {
            throw new InvalidArgumentException('Exchange parolası gerekli.');
        }

        foreach (['eas_url', 'domain', 'device_id', 'device_type', 'protocol_version'] as $required) {
            if (empty($config[$required])) {
                throw new RuntimeException("Eksik yapılandırma: {$required}");
            }
        }
    }

    public static function normaliseUsername(string $username): string
    {
        $value = trim($username);
        if (str_contains($value, '\\')) {
            $value = substr($value, (int) strrpos($value, '\\') + 1);
        }
        if (str_contains($value, '@')) {
            $value = strstr($value, '@', true) ?: '';
        }
        if ($value === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $value) !== 1) {
            throw new InvalidArgumentException('TGS kullanıcı adı geçersiz.');
        }
        return $value;
    }

    /**
     * @return list<array{id:string,parentId:string,name:string,type:int,role:string}>
     */
    public function listFolders(): array
    {
        $tree = $this->request('FolderSync', $this->folderSyncPayload());
        $status = $this->firstText($tree, 'Status');
        if ($status !== '' && $status !== '1') {
            throw new RuntimeException("Exchange klasör hatası: {$status}");
        }

        $records = array_merge(
            $this->findNodes($tree, 'Add'),
            $this->findNodes($tree, 'Update')
        );

        $folders = [];
        foreach ($records as $folder) {
            $id = $this->firstText($folder, 'ServerId');
            $name = $this->firstText($folder, 'DisplayName');
            $type = (int) $this->firstText($folder, 'Type');
            // Yalnızca gerçek posta klasörlerini göster; takvim, kişi, görev ve
            // Exchange'in sistem klasörleri posta arayüzüne karışmasın.
            if ($id === '' || $name === '' || !in_array($type, [1, 2, 3, 4, 5, 6, 12], true)) {
                continue;
            }
            $folders[] = [
                'id' => $id,
                'parentId' => $this->firstText($folder, 'ParentId'),
                'name' => $name,
                'type' => $type,
                'role' => $this->folderRole($type),
            ];
        }

        $order = ['inbox' => 0, 'sent' => 1, 'drafts' => 2, 'outbox' => 3, 'trash' => 4, 'other' => 5];
        usort($folders, static function (array $left, array $right) use ($order): int {
            $roleOrder = ($order[$left['role']] ?? 9) <=> ($order[$right['role']] ?? 9);
            return $roleOrder !== 0 ? $roleOrder : strnatcasecmp($left['name'], $right['name']);
        });

        return $folders;
    }

    /**
     * @return array{messages:list<array<string,mixed>>,moreAvailable:bool,pages:int}
     */
    public function listMessages(string $folderId): array
    {
        if ($folderId === '' || strlen($folderId) > 512) {
            throw new InvalidArgumentException('Klasör kimliği geçersiz.');
        }

        $initial = $this->request('Sync', $this->syncPayload($folderId, '0', false));
        $syncKey = $this->firstText($initial, 'SyncKey');
        if ($syncKey === '') {
            throw new RuntimeException('Exchange eşitleme anahtarını vermedi.');
        }

        $messages = [];
        $seen = [];
        $moreAvailable = false;
        $pages = 0;
        $maxPages = max(1, min(5, (int) ($this->config['max_sync_pages'] ?? 2)));

        do {
            $tree = $this->request('Sync', $this->syncPayload($folderId, $syncKey, true));
            $collection = $this->findNodes($tree, 'Collection')[0] ?? $tree;
            $status = $this->firstText($collection, 'Status');
            if ($status !== '' && $status !== '1') {
                throw new RuntimeException("Exchange eşitleme hatası: {$status}");
            }

            foreach ($this->findNodes($collection, 'Add') as $add) {
                $message = $this->messageFromNode($add);
                if ($message['id'] !== '' && !isset($seen[$message['id']])) {
                    $seen[$message['id']] = true;
                    $messages[] = $message;
                }
            }

            $nextSyncKey = $this->firstText($collection, 'SyncKey');
            if ($nextSyncKey === '') {
                throw new RuntimeException('Exchange sonraki eşitleme anahtarını vermedi.');
            }
            $syncKey = $nextSyncKey;
            $moreAvailable = count($this->findNodes($collection, 'MoreAvailable')) > 0;
            $pages++;
        } while ($moreAvailable && $pages < $maxPages);

        usort($messages, static fn(array $left, array $right): int => strcmp((string) $right['date'], (string) $left['date']));

        return [
            'messages' => $messages,
            'moreAvailable' => $moreAvailable,
            'pages' => $pages,
        ];
    }

    /** @return array<string, mixed> */
    private function messageFromNode(WbxmlNode $add): array
    {
        $data = $this->directChild($add, 'ApplicationData') ?? $add;
        $bodyNode = $this->directChild($data, 'Body');
        $body = $bodyNode ? $this->firstText($bodyNode, 'Data') : $this->firstText($data, 'Body');
        $preview = $bodyNode ? $this->firstText($bodyNode, 'Preview') : '';
        if ($preview === '') {
            $preview = $this->plainPreview($body);
        }

        $attachments = [];
        foreach ($this->findNodes($data, 'Attachment') as $attachment) {
            $id = $this->firstText($attachment, 'FileReference') ?: $this->firstText($attachment, 'Att0Id');
            $name = $this->firstText($attachment, 'DisplayName') ?: $this->firstText($attachment, 'AttName');
            if ($id !== '' && $name !== '') {
                $attachments[] = [
                    'id' => $id,
                    'name' => $name,
                    'size' => (int) ($this->firstText($attachment, 'EstimatedDataSize') ?: $this->firstText($attachment, 'AttSize')),
                    'contentType' => $this->firstText($attachment, 'ContentType'),
                ];
            }
        }

        return [
            'id' => $this->firstText($add, 'ServerId'),
            'subject' => $this->firstText($data, 'Subject'),
            'from' => $this->firstText($data, 'From') ?: $this->firstText($data, 'DisplayName'),
            'to' => $this->firstText($data, 'To'),
            'cc' => $this->firstText($data, 'Cc'),
            'date' => $this->firstText($data, 'DateReceived'),
            'read' => $this->firstText($data, 'Read') === '1',
            'preview' => $preview,
            'body' => $body,
            'attachments' => $attachments,
        ];
    }

    private function plainPreview(string $body): string
    {
        $text = strip_tags($body);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';
        return mb_substr(trim($text), 0, 280, 'UTF-8');
    }

    private function folderRole(int $type): string
    {
        return match ($type) {
            2 => 'inbox',
            3 => 'drafts',
            4 => 'trash',
            5 => 'sent',
            6 => 'outbox',
            default => 'other',
        };
    }

    private function folderSyncPayload(): string
    {
        return $this->document($this->tag(7, 22, $this->tag(7, 18, $this->inlineText('0'))));
    }

    private function syncPayload(string $folderId, string $syncKey, bool $getChanges): string
    {
        $collection =
            $this->tag(0, 11, $this->inlineText($syncKey)) .
            $this->tag(0, 18, $this->inlineText($folderId));

        if ($getChanges) {
            $windowSize = max(10, min(100, (int) ($this->config['window_size'] ?? 100)));
            $bodyPreference = $this->tag(17, 5,
                $this->tag(17, 6, $this->inlineText('1')) .
                $this->tag(17, 7, $this->inlineText('65536'))
            );
            $collection .=
                $this->tag(0, 30) .
                $this->tag(0, 19, $this->inlineText('1')) .
                $this->tag(0, 21, $this->inlineText((string) $windowSize)) .
                $this->tag(0, 23,
                    $this->tag(0, 24, $this->inlineText('0')) .
                    $bodyPreference
                );
        }

        return $this->document(
            $this->tag(0, 5,
                $this->tag(0, 28,
                    $this->tag(0, 15, $collection)
                )
            )
        );
    }

    private function document(string $root): string
    {
        return "\x03\x01\x6A\x00" . $root;
    }

    private function inlineText(string $value): string
    {
        return "\x03" . $value . "\x00";
    }

    private function tag(int $page, int $token, ?string $content = null): string
    {
        $hasContent = $content !== null;
        return "\x00" . chr($page) . chr($token | ($hasContent ? 0x40 : 0)) .
            ($content ?? '') . ($hasContent ? "\x01" : '');
    }

    private function request(string $command, string $payload): WbxmlNode
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Hostingde PHP cURL eklentisi etkin değil.');
        }

        $query = http_build_query([
            'Cmd' => $command,
            'User' => $this->alias,
            'DeviceId' => (string) $this->config['device_id'],
            'DeviceType' => (string) $this->config['device_type'],
        ], '', '&', PHP_QUERY_RFC3986);

        $url = rtrim((string) $this->config['eas_url'], '?') . '?' . $query;
        $domainUser = (string) $this->config['domain'] . '\\' . $this->alias;
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Exchange bağlantısı başlatılamadı.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($domainUser . ':' . $this->password),
                'MS-ASProtocolVersion: ' . (string) $this->config['protocol_version'],
                'User-Agent: BeyanPhpMail/1.0',
                'Content-Type: application/vnd.ms-sync.wbxml',
                'Accept: application/vnd.ms-sync.wbxml',
            ],
            CURLOPT_SSL_VERIFYPEER => (bool) ($this->config['verify_tls'] ?? true),
            CURLOPT_SSL_VERIFYHOST => ($this->config['verify_tls'] ?? true) ? 2 : 0,
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Exchange bağlantı hatası: ' . ($curlError ?: 'bilinmeyen hata'));
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Kullanıcı adı veya parola hatalı.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Exchange HTTP hatası: {$status}");
        }
        if ($response === '') {
            throw new RuntimeException('Exchange boş yanıt döndürdü.');
        }

        return $this->parseWbxml($response);
    }

    private function parseWbxml(string $bytes): WbxmlNode
    {
        $length = strlen($bytes);
        $index = 0;
        if ($length < 4) {
            throw new RuntimeException('Geçersiz WBXML yanıtı.');
        }

        $index++; // version
        $publicId = $this->readMbUInt($bytes, $index);
        if ($publicId === 0) {
            $this->readMbUInt($bytes, $index);
        }
        $this->readMbUInt($bytes, $index); // charset
        $stringTableLength = $this->readMbUInt($bytes, $index);
        $stringTable = substr($bytes, $index, $stringTableLength);
        $index += $stringTableLength;

        $page = 0;
        $root = new WbxmlNode('root');
        $stack = [$root];

        while ($index < $length) {
            $code = ord($bytes[$index++]);
            if ($code === 0x00) {
                if ($index >= $length) {
                    throw new RuntimeException('Eksik WBXML sayfa kodu.');
                }
                $page = ord($bytes[$index++]);
                continue;
            }
            if ($code === 0x01) {
                if (count($stack) > 1) {
                    array_pop($stack);
                }
                continue;
            }
            if ($code === 0x02) {
                $entity = $this->readMbUInt($bytes, $index);
                $stack[array_key_last($stack)]->text .= mb_chr($entity, 'UTF-8');
                continue;
            }
            if ($code === 0x03) {
                $end = strpos($bytes, "\x00", $index);
                if ($end === false) {
                    throw new RuntimeException('Eksik WBXML metin sonu.');
                }
                $stack[array_key_last($stack)]->text .= substr($bytes, $index, $end - $index);
                $index = $end + 1;
                continue;
            }
            if ($code === 0x83) {
                $offset = $this->readMbUInt($bytes, $index);
                $stack[array_key_last($stack)]->text .= $this->stringTableValue($stringTable, $offset);
                continue;
            }
            if ($code === 0xC3) {
                $size = $this->readMbUInt($bytes, $index);
                $stack[array_key_last($stack)]->binary = substr($bytes, $index, $size);
                $index += $size;
                continue;
            }
            if ($code < 5) {
                throw new RuntimeException("Desteklenmeyen WBXML kodu: {$code}");
            }

            $token = $code & 0x3F;
            $hasContent = ($code & 0x40) !== 0;
            $hasAttributes = ($code & 0x80) !== 0;
            if ($hasAttributes) {
                throw new RuntimeException('WBXML öznitelikleri desteklenmiyor.');
            }

            $node = new WbxmlNode(self::TAGS[$page][$token] ?? "p{$page}:{$token}");
            $parent = $stack[array_key_last($stack)];
            $parent->children[] = $node;
            if ($hasContent) {
                $stack[] = $node;
            }
        }

        return $root;
    }

    private function readMbUInt(string $bytes, int &$index): int
    {
        $value = 0;
        $length = strlen($bytes);
        do {
            if ($index >= $length) {
                throw new RuntimeException('Eksik WBXML çok baytlı sayı.');
            }
            $byte = ord($bytes[$index++]);
            $value = ($value << 7) | ($byte & 0x7F);
        } while (($byte & 0x80) !== 0);
        return $value;
    }

    private function stringTableValue(string $table, int $offset): string
    {
        if ($offset < 0 || $offset >= strlen($table)) {
            return '';
        }
        $end = strpos($table, "\x00", $offset);
        return $end === false ? substr($table, $offset) : substr($table, $offset, $end - $offset);
    }

    /** @return list<WbxmlNode> */
    private function findNodes(WbxmlNode $node, string $name): array
    {
        $found = [];
        if ($node->name === $name) {
            $found[] = $node;
        }
        foreach ($node->children as $child) {
            array_push($found, ...$this->findNodes($child, $name));
        }
        return $found;
    }

    private function firstText(WbxmlNode $node, string $name): string
    {
        $nodes = $this->findNodes($node, $name);
        return isset($nodes[0]) ? $nodes[0]->text : '';
    }

    private function directChild(WbxmlNode $node, string $name): ?WbxmlNode
    {
        foreach ($node->children as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }
        return null;
    }
}
