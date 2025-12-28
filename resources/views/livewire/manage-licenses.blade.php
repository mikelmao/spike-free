<div class="bg-white dark:bg-gray-800 shadow overflow-hidden sm:rounded-md">
    <div class="bg-white dark:bg-gray-800 px-4 py-5 border-b border-gray-200 dark:border-gray-700 sm:px-6">
        <div>
            <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white">{{ __('spike::translations.licenses') }}</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('spike::translations.licenses_description') }}</p>
        </div>
    </div>

    <ul role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
        @foreach($pools as $typeId => $data)
            @php $type = $data['type']; @endphp
            <li>
                <div class="w-full">
                    <div class="px-4 py-4 flex items-center sm:px-6">
                        <div class="min-w-0 flex-1 sm:flex sm:items-center sm:justify-between">
                            <div class="space-y-1">
                                {{-- License Type Heading --}}
                                <div class="flex items-center text-sm">
                                    <p class="font-medium text-brand truncate">{{ $type->name() }}</p>
                                    @if($type->bundles())
                                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                            ({{ __('spike::translations.includes_bundled_licenses', ['licenses' => $type->bundledLicensesDescription()]) }})
                                        </span>
                                    @endif
                                </div>

                                {{-- License Stats --}}
                                <div class="flex items-center text-sm text-gray-500 dark:text-gray-400 space-x-4">
                                    <span>{{ __('spike::translations.total') }}: <strong class="text-gray-900 dark:text-white">{{ $data['total'] }}</strong></span>
                                    <span>{{ __('spike::translations.available') }}: <strong class="{{ $data['available'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">{{ $data['available'] }}</strong></span>
                                    <span>{{ __('spike::translations.allocated') }}: <strong class="text-gray-900 dark:text-white">{{ $data['allocated'] }}</strong></span>
                                </div>

                                {{-- Breakdown --}}
                                @if($data['included'] > 0 || $data['bundled'] > 0 || $data['purchased'] > 0)
                                    <div class="flex items-center text-xs text-gray-400 dark:text-gray-500 space-x-3">
                                        @if($data['included'] > 0)
                                            <span>{{ __('spike::translations.from_plan') }}: {{ $data['included'] }}</span>
                                        @endif
                                        @if($data['bundled'] > 0)
                                            <span>{{ __('spike::translations.bundled') }}: {{ $data['bundled'] }}</span>
                                        @endif
                                        @if($data['purchased'] > 0)
                                            <span>{{ __('spike::translations.purchased') }}: {{ $data['purchased'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            {{-- Price --}}
                            <div class="mt-4 flex-shrink-0 sm:mt-0 sm:ml-5">
                                @if($type->priceId())
                                    <span class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ \Opcodes\Spike\Utils::formatAmount($type->priceInCents()) }}/{{ __('spike::translations.month') }}
                                    </span>
                                @else
                                    <span class="text-sm text-gray-500 dark:text-gray-400 italic">
                                        {{ __('spike::translations.included_in_plan') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Action Buttons --}}
                        @if($type->priceId())
                            <div class="min-w-[130px] flex justify-end ml-5">
                                <button
                                    class="text-sm font-medium flex-shrink-0 flex items-center rounded-md px-4 py-2 text-white bg-brand hover:opacity-80"
                                    wire:click="$dispatch('openModal', { component: 'spike::manage-license', arguments: { typeId: '{{ $typeId }}' } })"
                                >
                                    @if($data['purchased'] > 0)
                                        <x-spike::icons.edit class="size-4 mr-2"/>
                                        {{ __('spike::translations.manage') }}
                                    @else
                                        <x-spike::icons.add class="size-4 mr-2"/>
                                        {{ __('spike::translations.purchase') }}
                                    @endif
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    {{-- Payment Method Warning --}}
    @if(!$hasPaymentMethod)
        <div class="px-4 py-4 sm:px-6 bg-yellow-50 dark:bg-yellow-900/20 border-t border-yellow-100 dark:border-yellow-800">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-400">
                        {{ __('spike::translations.payment_method_required') }}
                    </h3>
                    <div class="mt-2 text-sm text-yellow-700 dark:text-yellow-500">
                        <p>{{ __('spike::translations.add_payment_method_to_purchase_licenses') }}</p>
                    </div>
                    <div class="mt-4">
                        <a href="{{ route('spike.invoices') }}" class="text-sm font-medium text-yellow-800 dark:text-yellow-400 hover:text-yellow-600 dark:hover:text-yellow-300">
                            {{ __('spike::translations.go_to_billing') }} &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

