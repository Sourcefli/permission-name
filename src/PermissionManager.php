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

    //TODO
    //public function only (...$abilities) {}

    //TODO
    //public function except (...$abilities) {}

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
            throw new PermissionLookupException("A scope type is not set. If using the `AllPermission` facade, call setScope(), passing in a valid scope, before calling on your resources. e.g. AllPermission::setScope('owned')->user()->browse()");
        }

        $this->validResourceIsSet();
        $this->reset();
        $this->filterForResource();
    }

    public function validResourceIsSet()
    {
        if (!isset($this->resource)) {
            throw new PermissionLookupException("A Resource must be set before retrieving a specific permission");
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

    protected function resourcePrefix()
    {
        // 'user.[owned]'
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
