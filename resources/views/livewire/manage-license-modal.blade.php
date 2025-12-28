<div class="w-full relative flex flex-col bg-white dark:bg-gray-800 pt-6 pb-8 overflow-hidden sm:pb-6 sm:rounded-lg lg:py-8">
    {{-- Header --}}
    <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8">
        <h2 class="text-lg font-medium text-gray-900 dark:text-white">
            {{ __('spike::translations.manage_licenses') }}
        </h2>
        <button type="button" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                wire:click="$dispatch('closeModal')"
                wire:loading.attr="disabled"
        >
            <span class="sr-only">{{ __('spike::translations.close_modal') }}</span>
            <x-spike::icons.dismiss class="size-6"/>
        </button>
    </div>

    {{-- Error Message --}}
    @if($errorMessage)
        <div class="mt-4 mx-4 sm:mx-6 lg:mx-8">
            <p class="text-red-700 dark:text-red-400 px-3 py-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-sm" role="alert">
                {{ $errorMessage }}
            </p>
        </div>
    @endif

    {{-- License Info --}}
    <section class="mt-6 px-4 sm:px-6 lg:px-8">
        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 sm:rounded-lg">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-medium text-gray-900 dark:text-white">{{ $type->name() }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ \Opcodes\Spike\Utils::formatAmount($priceInCents) }}/{{ __('spike::translations.month') }} {{ __('spike::translations.per_license') }}
                    </p>
                </div>
            </div>

            {{-- Current Stats --}}
            <div class="grid grid-cols-3 gap-4 text-center mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3">
                    <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $pool->total() }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('spike::translations.total') }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3">
                    <p class="text-2xl font-semibold {{ $pool->available() > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">{{ $pool->available() }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('spike::translations.available') }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3">
                    <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $pool->allocated() }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('spike::translations.allocated') }}</p>
                </div>
            </div>

            {{-- Quantity Selector --}}
            <div class="border-t border-gray-200 dark:border-gray-600 pt-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    {{ __('spike::translations.purchased_licenses') }}
                </label>
                <div class="flex items-center justify-center">
                    <button type="button"
                            class="inline-flex items-center justify-center w-10 h-10 rounded-l-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-brand disabled:opacity-50 disabled:cursor-not-allowed"
                            wire:click="decrement"
                            @if($quantity <= 0 || (!$canDecrease && $quantity <= $currentPurchased)) disabled @endif
                    >
                        <x-spike::icons.subtract class="size-5"/>
                    </button>
                    <input type="number"
                           wire:model.live="quantity"
                           min="0"
                           class="w-20 h-10 text-center border-t border-b border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand"
                    />
                    <button type="button"
                            class="inline-flex items-center justify-center w-10 h-10 rounded-r-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-brand"
                            wire:click="increment"
                    >
                        <x-spike::icons.add class="size-5"/>
                    </button>
                </div>

                @if($decreaseWarning)
                    <p class="mt-2 text-sm text-amber-600 dark:text-amber-400 text-center">{{ $decreaseWarning }}</p>
                @endif
            </div>
        </div>
    </section>

    {{-- Price Summary --}}
    @if($hasChanges)
    <section class="mt-4 px-4 sm:px-6 lg:px-8">
        <div class="bg-gray-50 dark:bg-gray-700/50 p-4 sm:rounded-lg">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('spike::translations.current_licenses') }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ $currentPurchased }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('spike::translations.new_licenses') }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ $quantity }}</dd>
                </div>
                <div class="flex justify-between border-t border-gray-200 dark:border-gray-600 pt-2">
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('spike::translations.change') }}</dt>
                    <dd class="font-medium {{ $isIncrease ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $isIncrease ? '+' : '' }}{{ $quantityChange }}
                    </dd>
                </div>
                <div class="flex justify-between border-t border-gray-200 dark:border-gray-600 pt-2">
                    <dt class="font-medium text-gray-900 dark:text-white">{{ __('spike::translations.monthly_change') }}</dt>
                    <dd class="font-medium {{ $isIncrease ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $isIncrease ? '+' : '' }}{{ \Opcodes\Spike\Utils::formatAmount($monthlyChange) }}/{{ __('spike::translations.month') }}
                    </dd>
                </div>
            </dl>
        </div>
    </section>
    @endif

    {{-- Payment Method Warning --}}
    @if(!$hasPaymentMethod && $isIncrease)
    <div class="mt-4 mx-4 sm:mx-6 lg:mx-8">
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-yellow-700 dark:text-yellow-400">
                        {{ __('spike::translations.add_payment_method_to_purchase_licenses') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Actions --}}
    <div class="mt-6 flex flex-col sm:flex-row sm:justify-end gap-3 px-4 sm:px-6 lg:px-8">
        <button type="button"
                class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand"
                wire:click="$dispatch('closeModal')"
        >
            {{ __('spike::translations.cancel') }}
        </button>
        <button type="button"
                class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand hover:opacity-80 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand disabled:opacity-50 disabled:cursor-not-allowed"
                wire:click="confirm"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-50"
                @if(!$hasChanges || (!$hasPaymentMethod && $isIncrease) || ($isDecrease && !$canDecrease)) disabled @endif
        >
            <x-spike::shared.spinner class="size-4 mr-2" wire:loading wire:target="confirm"/>
            @if($isIncrease)
                {{ __('spike::translations.confirm_purchase') }}
            @elseif($isDecrease)
                {{ __('spike::translations.confirm_removal') }}
            @else
                {{ __('spike::translations.confirm') }}
            @endif
        </button>
    </div>
</div>

