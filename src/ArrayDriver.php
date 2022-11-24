<?php

namespace Laravel\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Feature\Events\CheckingUnknownFeature;

class ArrayDriver
{
    /**
     * The event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * The current state of the features.
     *
     * @var array<string, bool>
     */
    protected $features = [];

    /**
     * The initial feature state resolvers.
     *
     * @var array<string, (callable(mixed): bool)>
     */
    protected $initialFeatureStateResolvers = [];

    /**
     * Create a new driver instance.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     */
    public function __construct($events)
    {
        $this->events = $events;
    }

    /**
     * Determine if the feature(s) is active for the given scope.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return bool
     */
    public function isActive($feature, $scope = null)
    {
        return $this->resolveFeatureKeys($feature, $scope)
            ->every(function ($feature, $key) use ($scope) {
                if (! array_key_exists($key, $this->features) && ! array_key_exists($feature, $this->initialFeatureStateResolvers)) {
                    $this->events->dispatch(new CheckingUnknownFeature($feature, $scope));

                    return false;
                }

                return $this->features[$key] ??= (bool) $this->initialFeatureStateResolvers[$feature]($scope);
            });
    }

    /**
     * Determine if the feature(s) is inactive for the given scope.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return bool
     */
    public function isInactive($feature, $scope = null)
    {
        // TODO: does this make sense to just invert?
        // I think there could be an issue here. Need to test futher.
        return ! $this->isActive($feature, $scope);
    }

    /**
     * Activate the feature(s) for the given scope.
     *
     * @param  string|array<int, string>  $feature
     * @param  mixed  $scope
     * @return void
     */
    public function activate($feature, $scope = null)
    {
        $this->features = array_merge(
            $this->features,
            $this->resolveFeatureKeys($feature, $scope)
                ->map(fn () => true)
                ->all()
        );
    }

    /**
     * Deactivate the feature(s) for the given scope.
     *
     * @param  string|array<int, string>  $feature
     * @param  mixed  $scope
     * @return void
     */
    public function deactivate($feature, $scope = null)
    {
        $this->features = array_merge(
            $this->features,
            $this->resolveFeatureKeys($feature, $scope)
                ->map(fn () => false)
                ->all()
        );
    }

    /**
     * Register an initial feature state resolver.
     *
     * @param  string  $feature
     * @param  (callable(mixed $scope): bool)  $resolver
     * @return $this
     */
    public function register($feature, $resolver)
    {
        $this->initialFeatureStateResolvers[$feature] = $resolver;

        return $this;
    }

    /**
     * Resolve all permutations of the feature(s) and scope.
     *
     * @param  string|array<string>  $feature
     * @param  mixed  $scope
     * @return \Illuminate\Support\Collection<string, string>
     */
    protected function resolveFeatureKeys($feature, $scope)
    {
        $scope = is_array($scope) ? $scope : [$scope];

        return Collection::make($scope)
            ->map(fn ($item) => $this->resolveKey($feature, $item))
            ->crossJoin(Arr::wrap($feature))
            ->mapWithKeys(fn ($value) => [
                ($value[0] === null ? "{$value[1]}" : "{$value[1]}:{$value[0]}") => $value[1],
            ]);
    }

    /**
     * Resolve the unique key for the given feature and scope.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return string
     */
    protected function resolveKey($feature, $scope)
    {
        if ($scope instanceof Model) {
            return $scope::class.':'.$scope->getKey();
        }

        // TODO: this needs to be improved. This is not okay and we will likely
        // have a contract that objects can implement to be identified, but
        // also allow drivers to override this resolution logic generally.
        // we should also use the morphMap

        return json_encode($scope);
    }
}