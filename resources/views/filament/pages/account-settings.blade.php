<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Email address</x-slot>

        {{ filament()->auth()->user()?->email }}
    </x-filament::section>
</x-filament-panels::page>
