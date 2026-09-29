<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_menus
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Menus\Administrator\Autosave;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\TargetAwareAutosaveProviderInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Filesystem\Path;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class ItemAutosaveProvider implements
    TargetAwareAutosaveProviderInterface,
    AutosaveCreateProviderInterface,
    AutosaveDynamicCreateDescriptorProviderInterface
{
    private const STRING_LIMITS = ['title' => 255, 'alias' => 400, 'note' => 255];

    private const DESCRIPTOR_PREFIX    = 'mi1';
    private const CREATE_CONTRACT      = 'menu-item-create-v1';
    private const DESCRIPTOR_CONTRACT  = 'menu-item-descriptor-v1';
    private const SPECIAL_TYPE_KEYS    = ['alias', 'url', 'separator', 'heading', 'container'];

    /** @var callable(object): AutosaveDynamicSchema */
    private $schemaResolver;

    /** @var callable(array, int): bool */
    private $supportResolver;

    public function __construct(private readonly DatabaseInterface $db, callable $schemaResolver, ?callable $supportResolver = null)
    {
        $this->schemaResolver = $schemaResolver;
        // Mirrors the native availability gates of the Menu Item type chooser: a
        // routed type is only selectable while its component is enabled and its
        // routed form definition is still resolvable on disk. Never trusts a bare
        // route string.
        $this->supportResolver = $supportResolver
            ?? static fn (array $shape, int $client): bool => !$shape['routed']
                || (ComponentHelper::isEnabled($shape['option']) && self::routeResolvable($shape['option'], $shape['view'], $shape['layout'], $client));
    }

    public function getContext(): string
    {
        return 'com_menus.item';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function getCreateContractVersion(): string
    {
        return self::CREATE_CONTRACT;
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // A Menu Item can only be authorized against its anchored routed creation
        // descriptor. The lifecycle routes every provisional operation of this
        // provider through authorizeStaticCreateScope(); reaching this method means
        // no anchored descriptor is available, so it fails closed instead of
        // guessing a target menu.
        throw new AutosaveException('scope_required', 'A Menu Item requires an anchored creation descriptor.');
    }

    public function getStaticScopeContractVersion(): string
    {
        return self::DESCRIPTOR_CONTRACT;
    }

    /**
     * Build the raw server-owned creation candidate for one new Menu Item editor.
     *
     * The candidate carries only the model state a genuine type selection binds into
     * the editor: client, target menu and the canonical routed type identity. It is
     * canonicalized and authorized once at render; the browser merely echoes the
     * anchored descriptor token back on initializeCreate.
     *
     * @return  string|null  null when the editor is not a genuine routed create form.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function createScopeCandidate(int $client, string $menuType, string $type, string $link): ?string
    {
        if (!$this->validClient((string) $client) || !$this->validMenuType($menuType) || $type === '') {
            return null;
        }

        if (\in_array($type, self::SPECIAL_TYPE_KEYS, true)) {
            $identityKey = $type;
        } elseif ($type === 'component') {
            $identityKey = $this->routeKey($link, 'component');
        } else {
            return null;
        }

        if ($identityKey === null) {
            return null;
        }

        return $client . '|' . $menuType . '|' . $identityKey;
    }

    public function canonicalizeStaticCreateScope(mixed $candidateScope): string
    {
        if (!\is_string($candidateScope)) {
            throw $this->invalidScope();
        }

        if (str_contains($candidateScope, '|')) {
            $raw = explode('|', $candidateScope, 3);

            if (\count($raw) !== 3) {
                throw $this->invalidScope();
            }

            [$client, $menuType, $identityKey] = $raw;
            $fingerprint                       = null;
        } else {
            $parts = explode(':', $candidateScope, 5);

            if (\count($parts) !== 5 || $parts[0] !== self::DESCRIPTOR_PREFIX) {
                throw $this->invalidScope();
            }

            [$client, $menuType, $identityKey, $fingerprint] = [$parts[1], $parts[2], $parts[3], $parts[4]];

            if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw $this->invalidScope();
            }
        }

        if (!$this->validClient($client) || !$this->validMenuType($menuType)) {
            throw $this->invalidScope();
        }

        $shape = $this->identityShape($identityKey, (int) $client);

        if ($shape === null || !$this->supported($shape, (int) $client)) {
            throw $this->invalidScope();
        }

        // The fingerprint must equal the schema the server can build right now from
        // the canonical routed identity. A candidate carrying a stale or fabricated
        // fingerprint is refused, so a P1 can never anchor a schema the server does
        // not own.
        $schema = ($this->schemaResolver)($this->pseudoRecord($shape, $menuType, (int) $client));

        if ($fingerprint !== null && !hash_equals($schema->fingerprint(), $fingerprint)) {
            throw $this->invalidScope();
        }

        return self::DESCRIPTOR_PREFIX . ':' . (int) $client . ':' . $menuType . ':' . $identityKey . ':' . $schema->fingerprint();
    }

    public function authorizeStaticCreateScope(User $user, string $canonicalScope, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        $shape = $this->parseDescriptor($canonicalScope);

        // The routed identity must still describe a real, enabled target. Re-evaluated
        // for every provisional operation so a disabled component or a type whose
        // routed form disappeared fails closed instead of being silently trusted.
        if (!$this->supported($shape, $shape['client'])) {
            throw new AutosaveException('forbidden', 'The Menu Item creation descriptor is no longer supported.');
        }

        $menuTypeId = $this->menuTypeId($shape['menutype'], $shape['client']);

        if ($menuTypeId === null) {
            throw new AutosaveException('forbidden', 'The Menu Item creation descriptor menu is unavailable.');
        }

        // Mirrors ItemController::allowAdd(): a Menu Item can only be created inside a
        // menu the user may create into. The anchored menu is the immutable creation
        // authority of the whole P1 lineage; permission revocation fails closed.
        if (!$user->authorise('core.create', 'com_menus.menu.' . $menuTypeId)) {
            throw new AutosaveException('forbidden', 'A Menu Item cannot be created in this menu.');
        }
    }

    public function verifyFinalTargetStaticScope(string $finalTargetId, string $canonicalScope): void
    {
        $shape  = $this->parseDescriptor($canonicalScope);
        $record = $this->load($this->canonicalizeTargetId($finalTargetId));

        if (
            $record === null
            || (int) $record->client_id !== $shape['client']
            || $record->menutype !== $shape['menutype']
            || (string) $record->type !== $shape['type']
        ) {
            throw new AutosaveException('scope_mismatch', 'The saved Menu Item does not match its creation descriptor.');
        }

        if ($shape['routed'] && $this->routeKey($record->link, $record->type) !== $shape['identityKey']) {
            throw new AutosaveException('scope_mismatch', 'The saved Menu Item route does not match its creation descriptor.');
        }
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        throw $this->invalidPayload();
    }

    public function normalizeCreatePayload(string $descriptor, mixed $payload, int $schemaVersion): array
    {
        $shape   = $this->parseDescriptor($descriptor);
        $current = ($this->schemaResolver)($this->pseudoRecord($shape, $shape['menutype'], $shape['client']));

        // The anchored descriptor must still describe the schema the server builds
        // right now. A routed component form or type file change therefore fails
        // closed deterministically instead of coercing the old draft into the new
        // schema.
        if (!hash_equals($current->fingerprint(), $shape['fingerprint'])) {
            throw new AutosaveException('descriptor_stale', 'The Menu Item creation descriptor schema is stale.');
        }

        return $this->assertPayloadWithSchema($current, $payload, $schemaVersion);
    }

    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array
    {
        $schema = $this->getDynamicSchema($targetId);

        return $this->assertPayloadWithSchema($schema, $payload, $schemaVersion);
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1 || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)) {
            throw new AutosaveException('invalid_target', 'The Menu Item target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        return $this->load($this->canonicalizeTargetId($targetId)) !== null;
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Menu Item was not found.');
        }

        if (!$user->authorise('core.edit', 'com_menus.menu.' . (int) $record->menu_type_id)) {
            throw new AutosaveException('forbidden', 'The Menu Item cannot be edited by this user.');
        }

        if ((int) $record->checked_out !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Menu Item is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Menu Item was not found.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_menus.item:base-revision:v1';

        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function getDynamicSchema(string $targetId): AutosaveDynamicSchema
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Menu Item was not found.');
        }

        return ($this->schemaResolver)($record);
    }

    public function getDynamicSchemaForForm(string $targetId, Form $form): AutosaveDynamicSchema
    {
        if (!$this->targetExists($targetId)) {
            throw new AutosaveException('target_not_found', 'The Menu Item was not found.');
        }

        return (new ItemAutosaveSchemaFactory())->fromForm($form);
    }

    /**
     * Reconstruct the descriptor-bound routed schema for one canonical scope.
     *
     * @param   string  $canonicalScope  The anchored canonical creation descriptor.
     *
     * @return  AutosaveDynamicSchema
     *
     * @throws  AutosaveException  invalid_scope when the descriptor cannot be parsed.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getDynamicSchemaForScope(string $canonicalScope): AutosaveDynamicSchema
    {
        $shape = $this->parseDescriptor($canonicalScope);

        return ($this->schemaResolver)($this->pseudoRecord($shape, $shape['menutype'], $shape['client']));
    }

    /**
     * Validate the bounded static Menu Item shape against one schema.
     *
     * @return  array{title: string, alias: string, note: string, browserNav: string, schemaFingerprint: string, params: mixed}
     */
    private function assertPayloadWithSchema(AutosaveDynamicSchema $schema, mixed $payload, int $schemaVersion): array
    {
        $keys = array_fill_keys([...array_keys(self::STRING_LIMITS), 'browserNav', 'schemaFingerprint', 'params'], true);

        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $keys) || array_diff_key($keys, $payload)) {
            throw $this->invalidPayload();
        }

        foreach (self::STRING_LIMITS as $name => $limit) {
            if (!\is_string($payload[$name]) || preg_match('//u', $payload[$name]) !== 1 || StringHelper::strlen($payload[$name]) > $limit) {
                throw $this->invalidPayload();
            }
        }

        if (!\is_string($payload['browserNav']) || !\in_array($payload['browserNav'], ['0', '1', '2'], true) || !\is_string($payload['schemaFingerprint'])) {
            throw $this->invalidPayload();
        }

        if (!hash_equals($schema->fingerprint(), $payload['schemaFingerprint'])) {
            throw $this->invalidPayload();
        }

        try {
            $params = $schema->normalizePayload(['params' => $payload['params']])['params'];
        } catch (\InvalidArgumentException) {
            throw $this->invalidPayload();
        }

        return array_merge(array_intersect_key($payload, self::STRING_LIMITS), ['browserNav' => $payload['browserNav'], 'schemaFingerprint' => $schema->fingerprint(), 'params' => $params]);
    }

    /**
     * Parse the canonical routed identity of a saved or provisional Menu Item link.
     */
    private function routeKey(string $link, string $type): ?string
    {
        if ($type !== 'component' || $link === '') {
            return \in_array($type, self::SPECIAL_TYPE_KEYS, true) ? $type : null;
        }

        $args = [];

        if ($link && ($query = parse_url($link, PHP_URL_QUERY))) {
            parse_str($query, $args);
        }

        if (!isset($args['option']) || !isset($args['view'])) {
            return null;
        }

        $option = $args['option'];
        $view   = $args['view'];
        $layout = $args['layout'] ?? 'default';

        return $this->routedKey($option, $view, $layout);
    }

    /**
     * @return array{routed: bool, type: string, option: ?string, view: ?string, layout: ?string}|null
     */
    private function identityShape(string $identityKey, int $client): ?array
    {
        if (\in_array($identityKey, self::SPECIAL_TYPE_KEYS, true)) {
            return ['routed' => false, 'type' => $identityKey];
        }

        if (preg_match('/^component\.(com_[a-z][a-z0-9_]{0,48})\.([A-Za-z0-9_-]{1,64})\.([A-Za-z0-9_-]{1,64})$/D', $identityKey, $matches) !== 1) {
            return null;
        }

        // Custom template layout overrides contain a colon and are rejected: the
        // routed schema identity stays bounded and canonical.
        return [
            'routed' => true,
            'type'   => 'component',
            'option' => $matches[1],
            'view'   => $matches[2],
            'layout' => $matches[3],
        ];
    }

    private function routedKey(string $option, string $view, string $layout): ?string
    {
        if (
            preg_match('/^com_[a-z][a-z0-9_]{0,48}$/D', $option) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $view) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $layout) !== 1
        ) {
            return null;
        }

        return 'component.' . $option . '.' . $view . '.' . $layout;
    }

    /**
     * The routed identity is only supported while its component is enabled and its
     * native routed form definition is still resolvable on disk.
     */
    private function supported(array $shape, int $client): bool
    {
        return ($this->supportResolver)($shape, $client);
    }

    /**
     * Replicate the native ItemModel routed XML lookup, excluding custom layouts.
     */
    private static function routeResolvable(string $option, string $view, string $layout, int $client): bool
    {
        try {
            $clientInfo = ApplicationHelper::getClientInfo($client);
            $base       = $clientInfo->path . '/components/' . $option;

            $path = Path::find(
                [$base . '/tmpl/' . $view, $base . '/views/' . $view . '/tmpl', $base . '/view/' . $view . '/tmpl'],
                $layout . '.xml'
            );

            if (\is_string($path) && is_file($path)) {
                return true;
            }

            $metadataFolders = [$base . '/view/' . $view, $base . '/views/' . $view];
            $metaPath        = Path::find($metadataFolders, 'metadata.xml');

            return $metaPath !== false && \is_string($metaPath) && is_file(Path::clean($metaPath));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{client: int, menutype: string, identityKey: string, routed: bool, type: string, option: ?string, view: ?string, layout: ?string, fingerprint: string}
     */
    private function parseDescriptor(string $descriptor): array
    {
        $parts = explode(':', $descriptor, 5);

        if (\count($parts) !== 5 || $parts[0] !== self::DESCRIPTOR_PREFIX) {
            throw new AutosaveException('invalid_scope', 'The Menu Item creation descriptor is invalid.');
        }

        [$prefix, $client, $menuType, $identityKey, $fingerprint] = $parts;

        if (!$this->validClient($client) || !$this->validMenuType($menuType) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new AutosaveException('invalid_scope', 'The Menu Item creation descriptor is invalid.');
        }

        $shape = $this->identityShape($identityKey, (int) $client);

        if ($shape === null || !$this->supported($shape, (int) $client)) {
            throw new AutosaveException('invalid_scope', 'The Menu Item creation descriptor is invalid.');
        }

        return $shape + ['client' => (int) $client, 'menutype' => $menuType, 'identityKey' => $identityKey, 'fingerprint' => $fingerprint];
    }

    private function pseudoRecord(array $shape, string $menuType, int $client): object
    {
        $record = (object) [
            'type'      => $shape['type'],
            'link'      => '',
            'client_id' => $client,
            'menutype'  => $menuType,
        ];

        if ($shape['routed']) {
            $record->link = 'index.php?option=' . $shape['option'] . '&view=' . $shape['view']
                . ($shape['layout'] !== 'default' ? '&layout=' . $shape['layout'] : '');
        }

        return $record;
    }

    private function validClient(string $client): bool
    {
        return preg_match('/^[01]$/D', $client) === 1;
    }

    private function validMenuType(string $menuType): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $menuType) === 1;
    }

    private function menuTypeId(string $menuType, int $client): ?int
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__menu_types'))
            ->where($this->db->quoteName('menutype') . ' = :menutype')
            ->where($this->db->quoteName('client_id') . ' = :client')
            ->bind(':menutype', $menuType)
            ->bind(':client', $client, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $id = $this->db->loadResult();

        return $id === null ? null : (int) $id;
    }

    private function load(string $targetId): ?object
    {
        $fields = ['m.id', 'm.menutype', 'm.title', 'm.alias', 'm.note', 'm.link', 'm.type', 'm.component_id', 'm.browserNav', 'm.params', 'm.checked_out', 'm.published', 'm.parent_id', 'm.access', 'm.language', 'm.home', 'mt.id AS menu_type_id', 'mt.client_id AS client_id'];
        $query  = $this->db->createQuery()->select($fields)->from($this->db->quoteName('#__menu', 'm'))->join('INNER', $this->db->quoteName('#__menu_types', 'mt') . ' ON mt.menutype = m.menutype')->where('m.id = :id')->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Menu Item draft payload is invalid.');
    }

    private function invalidScope(): AutosaveException
    {
        return new AutosaveException('invalid_scope', 'The Menu Item creation descriptor is invalid.');
    }
}
