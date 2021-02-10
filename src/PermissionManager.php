<?php


namespace Sourcefli\PermissionName;


use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Sourcefli\PermissionName\Adapters\AllPermissionsAdapter;
use Sourcefli\PermissionName\Adapters\OwnedPermissionsAdapter;
use Sourcefli\PermissionName\Adapters\OwnedSettingPermissionsAdapter;
use Sourcefli\PermissionName\Adapters\TeamPermissionsAdapter;
use Sourcefli\PermissionName\Adapters\TeamSettingPermissionsAdapter;
use Sourcefli\PermissionName\Exceptions\PermissionLookupException;
use Sourcefli\PermissionName\Factories\AllPermissions as AllPermissionsFactory;
use Sourcefli\PermissionName\Factories\PermissionNameFactory;

abstract class PermissionManager
{

    public Collection $abilities;
    protected string $resource;
    protected PermissionGenerator $generator;
    protected ?string $scopeType;
    protected Collection $permissions;
    protected array $resources = [];
    protected array $settings = [];


    public function __construct()
    {
        $this->scopeType = $this->runtimeOwnershipType();
        $this->validateScope();
        $this->resources = Meta::getResources();
        $this->settings = Meta::getSettings();
        $this->abilities = collect();
        $this->generator = new PermissionGenerator;
        $this->permissions = $this->filterForScope();
    }

    protected function validateScope($scopeType = null)
    {
        $scope = $scopeType ?? $this->scopeType;

        if (empty($scope) || !in_array($scope, PermissionGenerator::allScopes(), true)) {
            throw new PermissionLookupException(
                "Unable to determine resource scope. Please instantiate using one of the facades/adapters so it can determined automatically."
            );
        }
    }

    public function browse(): string
    {
        $this->resetAndReduceByResource();
        return $this->firstByAbility('browse');
    }

    public function read(): string
    {
        $this->resetAndReduceByResource();
        return $this->firstByAbility('read');
    }

    public function edit(): string
    {
        $this->resetAndReduceByResource();
        return $this->firstByAbility('edit');
    }

    public function add(): string
    {
        $this->resetAndReduceByResource();
        return $this->firstByAbility('add');
    }

    public function delete(): string
    {
        $this->resetAndReduceByResource();
        return $this->firstByAbility('delete');
    }

    public function force_delete(): string
    {
        $this->resetAndReduceByResource();
        return $this->firstByAbility('force_delete');
    }

    public function restore(): string
    {
        $this->resetAndReduceByResource();
        return $this->firstByAbility('restore');
    }

    public function wildcard(): string
    {
        $this->resetAndReduceByResource();
        return $this->firstByAbility('*');
    }

    public function only(string ...$abilities)
    {
        $this->resetAndReduceByResource();
        return $this->filterByAbilities(func_get_args());
    }

    public function except(string ...$abilities)
    {
        $this->resetAndReduceByResource();

        return $this->filterByAbilities($abilities, false);
    }

    public function all(): Collection
    {
        return $this->permissions->values();
    }

    protected function getAll(): Collection
    {
        return $this->generator->allPermissions();
    }

    protected function firstByAbility(string $ability)
    {
        return $this
            ->abilities
            ->first(fn ($p) => Str::endsWith($p, ".{$ability}"));
    }

    /**
     * @param Collection|array $abilities
     * @return mixed
     * @throws PermissionLookupException
     */
    protected function filterByAbilities($abilities, bool $preserveInput = true)
    {
        $this->validResourceIsSet();

        $matches = collect();

        foreach ($abilities as $ability) {
            $this->validateAbilityInput($ability);
            $matches->push(
                $this->extractAbilityFromAccessLevel($ability)
            );
        }

        return $preserveInput
            ? $matches
            : $this->abilities->diff($matches)->values();
    }

    public function setResource(string $resource): PermissionManager
    {
        if ($this->isSettingScope()) {
            $this->isValidSettingItem($resource);
            $this->resource = collect($this->settings)->first(fn ($s) => $s === $resource);
        } else {
            $this->isValidResource($resource);
            $this->resource = collect($this->resources)->first(fn ($s) => $s === $resource);
        }

        return $this;
    }

    public function resetAndReduceByResource()
    {
        if ($this->scopeIsAll()) {
            throw new PermissionLookupException("A scope type is not set. If using the `AllPermission` facade, call `setScope()` passing in a valid scope, before calling on your resources. e.g. AllPermission::setScope('owned')->user()->browse(). Valid scopes are: " . implode(', ', PermissionGenerator::allScopesForHumans()));
        }

        $this->validResourceIsSet();
        $this->reset();
        $this->filterForResource();

        return $this;
    }

    public function validResourceIsSet()
    {
        if (!isset($this->resource)) {
            throw new PermissionLookupException("A Resource must be set before proceeding. Use the `setResource()` method or chain the name of the resource (as a method). Example: if `user` is a resource, call `user()->browse()`");
        }
    }

    public function validateAbilityInput(string $ability)
    {
        $ability = strtolower($ability);

        if (!in_array($ability, $options = PermissionNameFactory::allAccessLevels(), true)) {
            throw new PermissionLookupException(
                "The parameters for the `only()` and `except()` methods should one or multiple of the following: " . implode(', ', $options)
            );
        }
    }

    public function filterForResource()
    {
        $this->abilities = $this
            ->permissions
            ->filter(
                fn ($p) =>
                Str::startsWith($p, $this->resourcePrefix())
            );

        return $this;
    }

    /**
     * @return $this
     */
    public function reset()
    {
        $this->abilities = collect();
        return $this;
    }

    protected function extractAbilityFromAccessLevel($accessLevel)
    {
        if (!$this->abilities->count()) {
            //In case being called from `AllPermissions`
            $this->resetAndReduceByResource();
        }

        foreach ($this->abilities->values() as $ability) {
            if (Str::endsWith($ability, strtolower($accessLevel))) {
                return $ability;
            }
        }

        throw new PermissionLookupException("`{$accessLevel}` is invalid. Please use one of the following: " . implode(', ', PermissionNameFactory::allAccessLevels()) . ". If passing multiple parameters, use a comma-seperated list of strings");
    }

    protected function resourcePrefix()
    {
        return $this->resource . "." . $this->scopeType;
    }

    protected function scopeIsAll()
    {
        return $this->scopeType === PermissionGenerator::SCOPE_ALL;
    }

    /**
     * @return Collection
     * @throws PermissionLookupException
     */
    protected function filterForScope()
    {
        return $this->generator->byScope($this->scopeType);
    }

    private function isValidSettingItem(string $settingItem)
    {
        if (!count($this->settings)) {
            throw new PermissionLookupException(
                "No setting-related permissions were specified within the `permission-name-generator.settings` configuration"
            );
        }

        $settingItem = Str::between($settingItem, '[', ']');

        if (!in_array($settingItem, $this->settings, true)) {
            throw new PermissionLookupException(
                "Settings permission of {$settingItem} is not a valid settings item. All settings-related permissions should be listed in the `permission-name-generator.settings` configuration."
            );
        }

        return true;
    }

    public function isValidResource(string $resource)
    {
        if (!count($this->resources)) {
            throw new PermissionLookupException(
                "No resources were specified within the `permission-name-generator.resources` configuration"
            );
        }

        if (!in_array($resource, $this->resources, true)) {
            throw new PermissionLookupException(
                "Resource of {$resource} is not valid. Only `resources` listed in the `config.permission-name-generator.resources` can be used as facade methods, or check out the `Sourcefli\PermissionName\Contracts\RetrievesPermissions` contract to see which retrieval methods can be chained. i.e. `OwnedPermission::user()->browse()`. `user` is the resource and `browse` is the retrieval method. This example would return the `user.owned.browse` permission"
            );
        }

        return true;
    }

    private function runtimeOwnershipType()
    {
        return $this instanceof OwnedPermissionsAdapter
            ? PermissionGenerator::SCOPE_OWNED
            : ($this instanceof TeamPermissionsAdapter
                ? PermissionGenerator::SCOPE_TEAM
                : ($this instanceof OwnedSettingPermissionsAdapter
                    ? PermissionGenerator::SCOPE_OWNED_SETTING
                    : ($this instanceof TeamSettingPermissionsAdapter
                        ? PermissionGenerator::SCOPE_TEAM_SETTING
                        : ($this instanceof AllPermissionsAdapter
                            ? PermissionGenerator::SCOPE_ALL
                            : null))));
    }

    public function __get($name)
    {
        $prop = $this->{$name};

        if (app()->environment() === 'testing' && isset($prop)) {
            return $prop;
        }

        throw new \InvalidArgumentException("No property exists named `{$name}` or you have to use a 'retrieval' method to access it");
    }

    public function __call($name, $arguments): PermissionManager
    {
        if (
            in_array($name, PermissionNameFactory::resourceRequiredAccess(), true) &&
            !isset($this->resource)
        ) {
            throw new PermissionLookupException("You must call the resource or setting name before trying to access the permission string. e.g. TeamPermission::billing()->edit(). `billing` represents a resource you have listed in your config file.");
        }

        if ($this->isSettingScope() && $this->isValidSettingItem($name)) {
            $this->setResource($name);
        } elseif ($this->isValidResource($name)) {
            $this->setResource($name);
        }

        return $this;
    }

    private function isSettingScope()
    {
        return $this->scopeType === PermissionGenerator::SCOPE_OWNED_SETTING || $this->scopeType === PermissionGenerator::SCOPE_TEAM_SETTING;
    }
}
