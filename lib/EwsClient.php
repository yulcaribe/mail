<?php
declare(strict_types=1);

final class EwsClient
{
    private const MESSAGES_NS = 'http://schemas.microsoft.com/exchange/services/2006/messages';
    private const TYPES_NS = 'http://schemas.microsoft.com/exchange/services/2006/types';
    private const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';

    /** @var array<string, mixed> */
    private array $config;
    private string $alias;
    private string $password;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, string $username, string $password)
    {
        $this->config = $config;
        $this->alias = EasClient::normaliseUsername($username);
        $this->password = $password;

        if (empty($config['ews_url']) || empty($config['domain'])) {
            throw new RuntimeException('EWS yapılandırması eksik.');
        }
        if ($password === '') {
            throw new InvalidArgumentException('Exchange parolası gerekli.');
        }
    }

    /**
     * @return list<array{id:string,changeKey:string,parentId:string,name:string,path:string}>
     */
    public function listMailFolders(): array
    {
        $body = '<m:FindFolder Traversal="Deep">' .
            '<m:FolderShape><t:BaseShape>IdOnly</t:BaseShape>' .
            '<t:AdditionalProperties>' .
            '<t:FieldURI FieldURI="folder:DisplayName" />' .
            '<t:FieldURI FieldURI="folder:ParentFolderId" />' .
            '<t:FieldURI FieldURI="folder:FolderClass" />' .
            '</t:AdditionalProperties>' .
            '</m:FolderShape>' .
            '<m:IndexedPageFolderView MaxEntriesReturned="1000" Offset="0" BasePoint="Beginning" />' .
            '<m:ParentFolderIds><t:DistinguishedFolderId Id="msgfolderroot" /></m:ParentFolderIds>' .
            '</m:FindFolder>';
        $document = $this->request('FindFolder', $body);
        $xpath = $this->xpath($document);
        $nodes = $xpath->query('//m:FindFolderResponseMessage/m:RootFolder/t:Folders/*');
        $folders = [];

        if ($nodes !== false) {
            foreach ($nodes as $folderNode) {
                if (!$folderNode instanceof DOMElement) {
                    continue;
                }
                if ($folderNode->localName !== 'Folder') {
                    continue;
                }
                $folderClass = $this->text($xpath, './t:FolderClass', $folderNode);
                if (!str_starts_with(strtoupper($folderClass), 'IPF.NOTE')) {
                    continue;
                }
                $folderIdNode = $xpath->query('./t:FolderId', $folderNode)?->item(0);
                if (!$folderIdNode instanceof DOMElement) {
                    continue;
                }
                $id = $folderIdNode->getAttribute('Id');
                $name = $this->text($xpath, './t:DisplayName', $folderNode);
                if ($id === '' || $name === '') {
                    continue;
                }
                $parentNode = $xpath->query('./t:ParentFolderId', $folderNode)?->item(0);
                $folders[$id] = [
                    'id' => $id,
                    'changeKey' => $folderIdNode->getAttribute('ChangeKey'),
                    'parentId' => $parentNode instanceof DOMElement ? $parentNode->getAttribute('Id') : '',
                    'name' => $name,
                    'path' => $name,
                ];
            }
        }

        foreach (array_keys($folders) as $id) {
            $folders[$id]['path'] = $this->folderPath($id, $folders);
        }

        $list = array_values($folders);
        usort($list, static fn(array $left, array $right): int => strnatcasecmp($left['path'], $right['path']));
        return $list;
    }

    /**
     * Normal posta klasörlerinde belirtilen tarihten önce alınmış mailleri
     * Çöp Kutusu'na taşır. Gönderilenler, Taslaklar, Giden Kutusu ve Çöp
     * Kutusu ile bunların alt klasörleri bu işlemden özellikle hariç tutulur.
     *
     * @return array{count:int,more:bool,folderCount:int}
     */
    public function moveReceivedMessagesBefore(DateTimeInterface $cutoff, int $limit = 500): array
    {
        if ($limit < 1 || $limit > 2000) {
            throw new InvalidArgumentException('Temizlik işlem sınırı geçersiz.');
        }

        $folders = $this->listMailFolders();
        $foldersById = [];
        foreach ($folders as $folder) {
            $foldersById[$folder['id']] = $folder;
        }

        $specialFolders = $this->distinguishedFolderIds([
            'sentitems',
            'drafts',
            'outbox',
            'deleteditems',
        ]);
        $excludedRoots = array_fill_keys(array_values($specialFolders), true);
        $targetFolderIds = [];
        foreach ($folders as $folder) {
            if (!$this->folderIsWithinRoots($folder['id'], $excludedRoots, $foldersById)) {
                $targetFolderIds[] = $folder['id'];
            }
        }

        if ($targetFolderIds === []) {
            return ['count' => 0, 'more' => false, 'folderCount' => 0];
        }

        $found = $this->findReceivedItemIdsBefore($targetFolderIds, $cutoff, $limit);
        if ($found['ids'] !== []) {
            $this->moveItemsToDeletedItems($found['ids']);
        }

        return [
            'count' => count($found['ids']),
            'more' => $found['more'],
            'folderCount' => count($targetFolderIds),
        ];
    }

    /**
     * Çöp Kutusu'ndaki bütün öğeleri Exchange tarafında kalıcı siler. Alt
     * klasör yapısını korumak için DeleteSubFolders bilerek false bırakılır.
     */
    public function emptyDeletedItems(): void
    {
        $deletedItemsId = $this->distinguishedFolderIds(['deleteditems'])['deleteditems'];
        $folders = $this->listMailFolders();
        $foldersById = [];
        foreach ($folders as $folder) {
            $foldersById[$folder['id']] = $folder;
        }

        $roots = [$deletedItemsId => true];
        $folderIds = [$deletedItemsId => $deletedItemsId];
        foreach ($folders as $folder) {
            if ($this->folderIsWithinRoots($folder['id'], $roots, $foldersById)) {
                $folderIds[$folder['id']] = $folder['id'];
            }
        }

        // Her klasörü ayrı hedefleyip alt klasör yapısını korurken içlerindeki
        // bütün öğeleri kalıcı olarak temizle.
        foreach (array_chunk(array_values($folderIds), 50) as $chunk) {
            $ids = '';
            foreach ($chunk as $folderId) {
                $ids .= '<t:FolderId Id="' . $this->xml($folderId) . '" />';
            }
            $body = '<m:EmptyFolder DeleteType="HardDelete" DeleteSubFolders="false">' .
                '<m:FolderIds>' . $ids . '</m:FolderIds></m:EmptyFolder>';
            $this->request('EmptyFolder', $body);
        }
    }

    /**
     * @param list<array{id:string,changeKey:string,parentId:string,name:string,path:string}> $folders
     * @return array{rules:list<array<string,mixed>>,raw:array<string,string>,outlookRuleBlobExists:bool}
     */
    public function listInboxRules(array $folders): array
    {
        $document = $this->request('GetInboxRules', '<m:GetInboxRules />');
        $xpath = $this->xpath($document);
        $blob = strtolower($this->text($xpath, '//m:GetInboxRulesResponse/m:OutlookRuleBlobExists')) === 'true';
        $folderNames = [];
        foreach ($folders as $folder) {
            $folderNames[$folder['id']] = $folder['path'];
        }

        $rules = [];
        $raw = [];
        $nodes = $xpath->query('//m:GetInboxRulesResponse/m:InboxRules/t:Rule');
        if ($nodes !== false) {
            foreach ($nodes as $ruleNode) {
                if (!$ruleNode instanceof DOMElement) {
                    continue;
                }
                $parsed = $this->parseRule($xpath, $ruleNode, $folderNames);
                if ($parsed['id'] === '') {
                    continue;
                }
                $rules[] = $parsed;
                $raw[$parsed['id']] = $document->saveXML($ruleNode) ?: '';
            }
        }

        usort($rules, static fn(array $left, array $right): int => ($left['priority'] <=> $right['priority']));
        return [
            'rules' => $rules,
            'raw' => $raw,
            'outlookRuleBlobExists' => $blob,
        ];
    }

    /** @param array<string, mixed> $data
     *  @param array{id:string,changeKey:string,parentId:string,name:string,path:string}|null $moveFolder
     */
    public function createSimpleRule(array $data, int $priority, ?array $moveFolder, bool $removeOutlookRuleBlob): void
    {
        $ruleXml = $this->simpleRuleXml($data, null, $priority, $moveFolder);
        $this->updateInboxRules('<t:CreateRuleOperation>' . $ruleXml . '</t:CreateRuleOperation>', $removeOutlookRuleBlob);
    }

    /** @param array<string, mixed> $data
     *  @param array{id:string,changeKey:string,parentId:string,name:string,path:string}|null $moveFolder
     */
    public function updateSimpleRule(string $ruleId, array $data, int $priority, ?array $moveFolder, bool $removeOutlookRuleBlob): void
    {
        if ($ruleId === '') {
            throw new InvalidArgumentException('Kural kimliği geçersiz.');
        }
        $ruleXml = $this->simpleRuleXml($data, $ruleId, $priority, $moveFolder);
        $this->updateInboxRules('<t:SetRuleOperation>' . $ruleXml . '</t:SetRuleOperation>', $removeOutlookRuleBlob);
    }

    public function toggleRule(string $rawRuleXml, bool $enabled, bool $removeOutlookRuleBlob): void
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        if (!$document->loadXML($rawRuleXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('Kural verisi okunamadı. Kuralları yenileyip tekrar deneyin.');
        }
        $xpath = $this->xpath($document);
        $enabledNode = $xpath->query('/t:Rule/t:IsEnabled')?->item(0);
        if (!$enabledNode instanceof DOMElement) {
            throw new RuntimeException('Kural etkinlik alanı bulunamadı.');
        }
        $enabledNode->nodeValue = $enabled ? 'true' : 'false';
        $ruleXml = $document->saveXML($document->documentElement) ?: '';
        $this->updateInboxRules('<t:SetRuleOperation>' . $ruleXml . '</t:SetRuleOperation>', $removeOutlookRuleBlob);
    }

    public function deleteRule(string $ruleId, bool $removeOutlookRuleBlob): void
    {
        if ($ruleId === '' || strlen($ruleId) > 1024) {
            throw new InvalidArgumentException('Kural kimliği geçersiz.');
        }
        $operation = '<t:DeleteRuleOperation><t:RuleId>' . $this->xml($ruleId) . '</t:RuleId></t:DeleteRuleOperation>';
        $this->updateInboxRules($operation, $removeOutlookRuleBlob);
    }

    private function updateInboxRules(string $operationXml, bool $removeOutlookRuleBlob): void
    {
        $body = '<m:UpdateInboxRules>' .
            '<m:RemoveOutlookRuleBlob>' . ($removeOutlookRuleBlob ? 'true' : 'false') . '</m:RemoveOutlookRuleBlob>' .
            '<m:Operations>' . $operationXml . '</m:Operations>' .
            '</m:UpdateInboxRules>';
        $this->request('UpdateInboxRules', $body);
    }

    /** @param array<string, mixed> $data
     *  @param array{id:string,changeKey:string,parentId:string,name:string,path:string}|null $moveFolder
     */
    private function simpleRuleXml(array $data, ?string $ruleId, int $priority, ?array $moveFolder): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        $fromAddress = trim((string) ($data['fromAddress'] ?? ''));
        $subjectContains = trim((string) ($data['subjectContains'] ?? ''));
        $hasAttachments = (bool) ($data['hasAttachments'] ?? false);
        $action = (string) ($data['action'] ?? '');
        $markAsRead = (bool) ($data['markAsRead'] ?? false) || $action === 'markRead';
        $stopProcessing = (bool) ($data['stopProcessing'] ?? true);
        $enabled = (bool) ($data['enabled'] ?? true);

        if ($name === '' || mb_strlen($name, 'UTF-8') > 128) {
            throw new InvalidArgumentException('Kural adı 1 ile 128 karakter arasında olmalıdır.');
        }
        if ($fromAddress === '' && $subjectContains === '' && !$hasAttachments) {
            throw new InvalidArgumentException('En az bir kural koşulu seçin.');
        }
        if (!in_array($action, ['move', 'delete', 'markRead'], true)) {
            throw new InvalidArgumentException('Kural eylemi geçersiz.');
        }
        if ($action === 'move' && $moveFolder === null) {
            throw new InvalidArgumentException('Taşınacak klasörü seçin.');
        }

        $conditions = '';
        if ($subjectContains !== '') {
            $conditions .= '<t:ContainsSubjectStrings><t:String>' . $this->xml(mb_substr($subjectContains, 0, 255, 'UTF-8')) . '</t:String></t:ContainsSubjectStrings>';
        }
        if ($fromAddress !== '') {
            $address = mb_substr($fromAddress, 0, 320, 'UTF-8');
            $conditions .= '<t:FromAddresses><t:Address><t:Name>' . $this->xml($address) . '</t:Name>' .
                '<t:EmailAddress>' . $this->xml($address) . '</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType></t:Address></t:FromAddresses>';
        }
        if ($hasAttachments) {
            $conditions .= '<t:HasAttachments>true</t:HasAttachments>';
        }

        $actions = '';
        if ($action === 'move' && $moveFolder !== null) {
            $attributes = ' Id="' . $this->xml($moveFolder['id']) . '"';
            if ($moveFolder['changeKey'] !== '') {
                $attributes .= ' ChangeKey="' . $this->xml($moveFolder['changeKey']) . '"';
            }
            $actions .= '<t:MoveToFolder><t:FolderId' . $attributes . ' /></t:MoveToFolder>';
        } elseif ($action === 'delete') {
            $actions .= '<t:Delete>true</t:Delete>';
        }
        if ($markAsRead) {
            $actions .= '<t:MarkAsRead>true</t:MarkAsRead>';
        }
        if ($stopProcessing) {
            $actions .= '<t:StopProcessingRules>true</t:StopProcessingRules>';
        }

        return '<t:Rule>' .
            ($ruleId !== null ? '<t:RuleId>' . $this->xml($ruleId) . '</t:RuleId>' : '') .
            '<t:DisplayName>' . $this->xml($name) . '</t:DisplayName>' .
            '<t:Priority>' . max(1, $priority) . '</t:Priority>' .
            '<t:IsEnabled>' . ($enabled ? 'true' : 'false') . '</t:IsEnabled>' .
            '<t:IsInError>false</t:IsInError>' .
            '<t:Conditions>' . $conditions . '</t:Conditions>' .
            '<t:Exceptions />' .
            '<t:Actions>' . $actions . '</t:Actions>' .
            '</t:Rule>';
    }

    /** @param array<string,string> $folderNames
     *  @return array<string,mixed>
     */
    private function parseRule(DOMXPath $xpath, DOMElement $rule, array $folderNames): array
    {
        $id = $this->text($xpath, './t:RuleId', $rule);
        $conditionNodes = $xpath->query('./t:Conditions/*', $rule);
        $actionNodes = $xpath->query('./t:Actions/*', $rule);
        $exceptionNodes = $xpath->query('./t:Exceptions/*', $rule);
        $allowedConditions = ['ContainsSubjectStrings', 'FromAddresses', 'HasAttachments'];
        $allowedActions = ['MoveToFolder', 'Delete', 'MarkAsRead', 'StopProcessingRules'];
        $editable = strtolower($this->text($xpath, './t:IsNotSupported', $rule)) !== 'true';

        $conditionNames = [];
        if ($conditionNodes !== false) {
            foreach ($conditionNodes as $node) {
                $conditionNames[] = $node->localName;
                if (!in_array($node->localName, $allowedConditions, true)) {
                    $editable = false;
                }
            }
        }
        $actionNames = [];
        if ($actionNodes !== false) {
            foreach ($actionNodes as $node) {
                $actionNames[] = $node->localName;
                if (!in_array($node->localName, $allowedActions, true)) {
                    $editable = false;
                }
            }
        }
        if ($exceptionNodes !== false && $exceptionNodes->length > 0) {
            $editable = false;
        }

        $fromAddresses = $this->texts($xpath, './t:Conditions/t:FromAddresses/t:Address/t:EmailAddress', $rule);
        $subjectStrings = $this->texts($xpath, './t:Conditions/t:ContainsSubjectStrings/t:String', $rule);
        if (count($fromAddresses) > 1 || count($subjectStrings) > 1) {
            $editable = false;
        }

        $moveNode = $xpath->query('./t:Actions/t:MoveToFolder/t:FolderId', $rule)?->item(0);
        $moveFolderId = $moveNode instanceof DOMElement ? $moveNode->getAttribute('Id') : '';
        if ($moveFolderId !== '' && !isset($folderNames[$moveFolderId])) {
            $editable = false;
        }
        $hasMove = in_array('MoveToFolder', $actionNames, true);
        $hasDelete = in_array('Delete', $actionNames, true);
        $hasMarkRead = in_array('MarkAsRead', $actionNames, true);
        if (($hasMove ? 1 : 0) + ($hasDelete ? 1 : 0) + ($hasMarkRead && !$hasMove && !$hasDelete ? 1 : 0) !== 1) {
            $editable = false;
        }

        $conditions = $this->conditionSummaries($xpath, $rule);
        $actions = $this->actionSummaries($xpath, $rule, $folderNames);
        $exceptions = $this->genericSummaries($xpath, './t:Exceptions/*', $rule);

        return [
            'id' => $id,
            'name' => $this->text($xpath, './t:DisplayName', $rule) ?: '(Adsız kural)',
            'priority' => (int) $this->text($xpath, './t:Priority', $rule),
            'enabled' => strtolower($this->text($xpath, './t:IsEnabled', $rule)) === 'true',
            'inError' => strtolower($this->text($xpath, './t:IsInError', $rule)) === 'true',
            'notSupported' => strtolower($this->text($xpath, './t:IsNotSupported', $rule)) === 'true',
            'editable' => $editable,
            'conditions' => $conditions !== [] ? $conditions : ['Her gelen mail'],
            'actions' => $actions !== [] ? $actions : ['Eylem belirtilmemiş'],
            'exceptions' => $exceptions,
            'form' => [
                'name' => $this->text($xpath, './t:DisplayName', $rule),
                'fromAddress' => $fromAddresses[0] ?? '',
                'subjectContains' => $subjectStrings[0] ?? '',
                'hasAttachments' => strtolower($this->text($xpath, './t:Conditions/t:HasAttachments', $rule)) === 'true',
                'action' => $hasMove ? 'move' : ($hasDelete ? 'delete' : 'markRead'),
                'moveFolderId' => $moveFolderId,
                'markAsRead' => $hasMarkRead,
                'stopProcessing' => strtolower($this->text($xpath, './t:Actions/t:StopProcessingRules', $rule)) === 'true',
                'enabled' => strtolower($this->text($xpath, './t:IsEnabled', $rule)) === 'true',
            ],
        ];
    }

    /** @return list<string> */
    private function conditionSummaries(DOMXPath $xpath, DOMElement $rule): array
    {
        $summaries = [];
        foreach ($this->texts($xpath, './t:Conditions/t:ContainsSubjectStrings/t:String', $rule) as $value) {
            $summaries[] = 'Konu “' . $value . '” içeriyorsa';
        }
        foreach ($this->texts($xpath, './t:Conditions/t:FromAddresses/t:Address/t:EmailAddress', $rule) as $value) {
            $summaries[] = 'Gönderen ' . $value . ' ise';
        }
        if (strtolower($this->text($xpath, './t:Conditions/t:HasAttachments', $rule)) === 'true') {
            $summaries[] = 'Ek içeriyorsa';
        }
        foreach ($this->genericSummaries($xpath, './t:Conditions/*', $rule) as $generic) {
            if (!in_array($generic, ['ContainsSubjectStrings', 'FromAddresses', 'HasAttachments'], true)) {
                $summaries[] = 'Diğer koşul: ' . $generic;
            }
        }
        return array_values(array_unique($summaries));
    }

    /** @param array<string,string> $folderNames
     *  @return list<string>
     */
    private function actionSummaries(DOMXPath $xpath, DOMElement $rule, array $folderNames): array
    {
        $summaries = [];
        $folderNode = $xpath->query('./t:Actions/t:MoveToFolder/t:FolderId', $rule)?->item(0);
        if ($folderNode instanceof DOMElement) {
            $id = $folderNode->getAttribute('Id');
            $summaries[] = '“' . ($folderNames[$id] ?? 'Bilinmeyen klasör') . '” klasörüne taşı';
        }
        if (strtolower($this->text($xpath, './t:Actions/t:Delete', $rule)) === 'true') {
            $summaries[] = 'Sil';
        }
        if (strtolower($this->text($xpath, './t:Actions/t:PermanentDelete', $rule)) === 'true') {
            $summaries[] = 'Kalıcı sil';
        }
        if (strtolower($this->text($xpath, './t:Actions/t:MarkAsRead', $rule)) === 'true') {
            $summaries[] = 'Okundu işaretle';
        }
        if (strtolower($this->text($xpath, './t:Actions/t:StopProcessingRules', $rule)) === 'true') {
            $summaries[] = 'Sonraki kuralları durdur';
        }
        foreach ($this->genericSummaries($xpath, './t:Actions/*', $rule) as $generic) {
            if (!in_array($generic, ['MoveToFolder', 'Delete', 'PermanentDelete', 'MarkAsRead', 'StopProcessingRules'], true)) {
                $summaries[] = 'Diğer eylem: ' . $generic;
            }
        }
        return array_values(array_unique($summaries));
    }

    /** @return list<string> */
    private function genericSummaries(DOMXPath $xpath, string $query, DOMElement $context): array
    {
        $result = [];
        $nodes = $xpath->query($query, $context);
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $result[] = $node->localName;
            }
        }
        return $result;
    }

    /** @param list<string> $names
     *  @return array<string,string>
     */
    private function distinguishedFolderIds(array $names): array
    {
        $folderIds = '';
        foreach ($names as $name) {
            $folderIds .= '<t:DistinguishedFolderId Id="' . $this->xml($name) . '" />';
        }
        $body = '<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape></m:FolderShape>' .
            '<m:FolderIds>' . $folderIds . '</m:FolderIds></m:GetFolder>';
        $document = $this->request('GetFolder', $body);
        $xpath = $this->xpath($document);
        $responses = $xpath->query('//m:GetFolderResponse/m:ResponseMessages/m:GetFolderResponseMessage');
        $result = [];

        if ($responses !== false) {
            foreach ($responses as $index => $response) {
                if (!$response instanceof DOMElement || !isset($names[$index])) {
                    continue;
                }
                $code = $this->text($xpath, './m:ResponseCode', $response);
                if ($code !== 'NoError') {
                    $message = $this->text($xpath, './m:MessageText', $response);
                    throw new RuntimeException('Exchange klasör kimliği alınamadı: ' . $code . ($message !== '' ? ' — ' . $message : ''));
                }
                $idNode = $xpath->query('./m:Folders/*/t:FolderId', $response)?->item(0);
                if ($idNode instanceof DOMElement && $idNode->getAttribute('Id') !== '') {
                    $result[$names[$index]] = $idNode->getAttribute('Id');
                }
            }
        }

        if (count($result) !== count($names)) {
            throw new RuntimeException('Exchange özel posta klasörlerinin tümünü döndürmedi.');
        }
        return $result;
    }

    /**
     * @param array<string,bool> $roots
     * @param array<string,array{id:string,changeKey:string,parentId:string,name:string,path:string}> $folders
     */
    private function folderIsWithinRoots(string $folderId, array $roots, array $folders): bool
    {
        $current = $folderId;
        $seen = [];
        while ($current !== '' && !isset($seen[$current])) {
            if (isset($roots[$current])) {
                return true;
            }
            $seen[$current] = true;
            $current = $folders[$current]['parentId'] ?? '';
        }
        return false;
    }

    /** @param list<string> $folderIds
     *  @return array{ids:list<string>,more:bool}
     */
    private function findReceivedItemIdsBefore(array $folderIds, DateTimeInterface $cutoff, int $limit): array
    {
        $cutoffUtc = DateTimeImmutable::createFromInterface($cutoff)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $ids = [];
        $more = false;

        foreach (array_chunk($folderIds, 25) as $folderChunk) {
            $parentFolders = '';
            foreach ($folderChunk as $folderId) {
                $parentFolders .= '<t:FolderId Id="' . $this->xml($folderId) . '" />';
            }
            $body = '<m:FindItem Traversal="Shallow">' .
                '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape>' .
                '<m:IndexedPageItemView MaxEntriesReturned="100" Offset="0" BasePoint="Beginning" />' .
                '<m:Restriction><t:IsLessThan>' .
                '<t:FieldURI FieldURI="item:DateTimeReceived" />' .
                '<t:FieldURIOrConstant><t:Constant Value="' . $this->xml($cutoffUtc) . '" /></t:FieldURIOrConstant>' .
                '</t:IsLessThan></m:Restriction>' .
                '<m:ParentFolderIds>' . $parentFolders . '</m:ParentFolderIds>' .
                '</m:FindItem>';
            $document = $this->request('FindItem', $body);
            $xpath = $this->xpath($document);
            $responses = $xpath->query('//m:FindItemResponse/m:ResponseMessages/m:FindItemResponseMessage');

            if ($responses === false) {
                continue;
            }
            foreach ($responses as $response) {
                if (!$response instanceof DOMElement) {
                    continue;
                }
                $code = $this->text($xpath, './m:ResponseCode', $response);
                if ($code !== 'NoError') {
                    $message = $this->text($xpath, './m:MessageText', $response);
                    throw new RuntimeException('Exchange mail araması başarısız: ' . $code . ($message !== '' ? ' — ' . $message : ''));
                }
                $root = $xpath->query('./m:RootFolder', $response)?->item(0);
                if ($root instanceof DOMElement && strtolower($root->getAttribute('IncludesLastItemInRange')) === 'false') {
                    $more = true;
                }
                $itemNodes = $xpath->query('./m:RootFolder/t:Items/*/t:ItemId', $response);
                if ($itemNodes === false) {
                    continue;
                }
                foreach ($itemNodes as $itemNode) {
                    if (!$itemNode instanceof DOMElement || $itemNode->getAttribute('Id') === '') {
                        continue;
                    }
                    $ids[$itemNode->getAttribute('Id')] = $itemNode->getAttribute('Id');
                    if (count($ids) >= $limit) {
                        return ['ids' => array_values($ids), 'more' => true];
                    }
                }
            }
        }

        return ['ids' => array_values($ids), 'more' => $ids !== [] && $more];
    }

    /** @param list<string> $itemIds */
    private function moveItemsToDeletedItems(array $itemIds): void
    {
        foreach (array_chunk($itemIds, 100) as $chunk) {
            $ids = '';
            foreach ($chunk as $itemId) {
                $ids .= '<t:ItemId Id="' . $this->xml($itemId) . '" />';
            }
            $body = '<m:DeleteItem DeleteType="MoveToDeletedItems" SuppressReadReceipts="true">' .
                '<m:ItemIds>' . $ids . '</m:ItemIds></m:DeleteItem>';
            $this->request('DeleteItem', $body);
        }
    }

    /** @param array<string,array{id:string,changeKey:string,parentId:string,name:string,path:string}> $folders */
    private function folderPath(string $id, array $folders): string
    {
        $parts = [];
        $current = $id;
        $seen = [];
        while (isset($folders[$current]) && !isset($seen[$current]) && count($parts) < 12) {
            $seen[$current] = true;
            array_unshift($parts, $folders[$current]['name']);
            $current = $folders[$current]['parentId'];
        }
        return implode(' / ', $parts);
    }

    private function request(string $operation, string $body): DOMDocument
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Hostingde PHP cURL eklentisi etkin değil.');
        }
        $curlInfo = curl_version();
        if (defined('CURL_VERSION_NTLM') && (((int) ($curlInfo['features'] ?? 0)) & CURL_VERSION_NTLM) === 0) {
            throw new RuntimeException('Hostingde cURL NTLM desteği etkin değil; Exchange EWS işlemleri kullanılamaz.');
        }

        $soap = '<?xml version="1.0" encoding="utf-8"?>' .
            '<soap:Envelope xmlns:soap="' . self::SOAP_NS . '" xmlns:m="' . self::MESSAGES_NS . '" xmlns:t="' . self::TYPES_NS . '">' .
            '<soap:Header><t:RequestServerVersion Version="Exchange2013" /></soap:Header>' .
            '<soap:Body>' . $body . '</soap:Body></soap:Envelope>';
        $domainUser = (string) $this->config['domain'] . '\\' . $this->alias;
        $curl = curl_init((string) $this->config['ews_url']);
        if ($curl === false) {
            throw new RuntimeException('EWS bağlantısı başlatılamadı.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soap,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
            CURLOPT_USERPWD => $domainUser . ':' . $this->password,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'Accept: text/xml',
                'SOAPAction: "' . self::MESSAGES_NS . '/' . $operation . '"',
                'User-Agent: BeyanPhpMail/2.0',
            ],
            CURLOPT_SSL_VERIFYPEER => (bool) ($this->config['verify_tls'] ?? true),
            CURLOPT_SSL_VERIFYHOST => ($this->config['verify_tls'] ?? true) ? 2 : 0,
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('EWS bağlantı hatası: ' . ($curlError ?: 'bilinmeyen hata'));
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Kullanıcı adı veya parola hatalı ya da EWS erişim yetkiniz yok.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("EWS HTTP hatası: {$status}");
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($response, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('EWS geçersiz XML yanıtı döndürdü.');
        }

        $xpath = $this->xpath($document);
        $fault = $this->text($xpath, '//soap:Fault/faultstring');
        if ($fault !== '') {
            throw new RuntimeException('Exchange EWS hatası: ' . mb_substr($fault, 0, 300, 'UTF-8'));
        }
        $responseCodes = $xpath->query('//*[local-name()="ResponseCode"]');
        if ($responseCodes !== false) {
            foreach ($responseCodes as $responseCodeNode) {
                $responseCode = trim((string) $responseCodeNode->textContent);
                if ($responseCode === '' || $responseCode === 'NoError') {
                    continue;
                }
                $message = $this->text($xpath, './*[local-name()="MessageText"]', $responseCodeNode->parentNode);
                throw new RuntimeException('Exchange EWS hatası: ' . $responseCode . ($message !== '' ? ' — ' . $message : ''));
            }
        }
        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('m', self::MESSAGES_NS);
        $xpath->registerNamespace('t', self::TYPES_NS);
        $xpath->registerNamespace('soap', self::SOAP_NS);
        return $xpath;
    }

    private function text(DOMXPath $xpath, string $query, ?DOMNode $context = null): string
    {
        $node = $xpath->query($query, $context)?->item(0);
        return $node instanceof DOMNode ? trim($node->textContent) : '';
    }

    /** @return list<string> */
    private function texts(DOMXPath $xpath, string $query, DOMNode $context): array
    {
        $result = [];
        $nodes = $xpath->query($query, $context);
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $value = trim($node->textContent);
                if ($value !== '') {
                    $result[] = $value;
                }
            }
        }
        return $result;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
