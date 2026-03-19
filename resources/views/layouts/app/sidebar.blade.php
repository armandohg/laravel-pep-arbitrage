<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="arrows-right-left" :href="route('transfers')" :current="request()->routeIs('transfers')" wire:navigate>
                        {{ __('Transfers') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="adjustments-horizontal" :href="route('settings.arbitrage')" :current="request()->routeIs('settings.arbitrage')" wire:navigate>
                        {{ __('Settings') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        {{-- Deployment refresh notifier --}}
        @php($deployVersion = file_exists(public_path('version.txt')) ? trim(file_get_contents(public_path('version.txt'))) : null)
        @if($deployVersion)
        <div
            x-data="{
                version: '{{ $deployVersion }}',
                updateAvailable: false,
                async check() {
                    try {
                        const res = await fetch('/version.txt?t=' + Date.now());
                        if (!res.ok) return;
                        const v = (await res.text()).trim();
                        if (v && v !== this.version) this.updateAvailable = true;
                    } catch {}
                },
                init() { setInterval(() => this.check(), 10_000); }
            }"
        >
            <div
                x-show="updateAvailable"
                x-transition.opacity
                class="fixed bottom-4 right-4 z-50 w-80"
                style="display:none"
            >
                <flux:card class="shadow-lg">
                    <div class="flex items-start gap-3">
                        <flux:icon.arrow-path class="mt-0.5 size-5 shrink-0 text-blue-500" />
                        <div class="flex-1">
                            <flux:heading size="sm">New version available</flux:heading>
                            <flux:text class="mt-1 text-sm">A new deployment is ready. Refresh to get the latest version.</flux:text>
                            <div class="mt-3 flex gap-2">
                                <flux:button size="sm" variant="primary" onclick="window.location.reload()">
                                    Refresh now
                                </flux:button>
                                <flux:button size="sm" variant="ghost" @click="updateAvailable = false">
                                    Dismiss
                                </flux:button>
                            </div>
                        </div>
                    </div>
                </flux:card>
            </div>
        </div>
        @endif

        @fluxScripts
    </body>
</html>
