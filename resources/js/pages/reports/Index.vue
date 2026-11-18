<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';

type Money = {
    label: string;
    exact: string;
    nanodollars: number;
};

type UserRow = {
    name: string;
    credits: Money;
    provider_cost: Money;
    profit: Money;
};

type Day = {
    date: string;
    credits: Money;
    provider_cost: Money;
    profit: Money;
    usage: Money | null;
    matches: boolean;
    users: UserRow[];
};

defineProps<{
    days: Day[];
    usage_available: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Report',
                href: '/reports',
            },
        ],
    },
});
</script>

<template>
    <Head title="Report" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Report"
            description="Credits, provider cost, and profit by the UTC day a generation finished."
        />

        <p v-if="!usage_available" class="text-sm text-red-600 dark:text-red-400">
            Pixelforge usage is temporarily unavailable.
        </p>

        <p v-if="days.length === 0" class="text-sm text-muted-foreground">No finished generations yet.</p>

        <section v-for="day in days" :key="day.date" class="space-y-3 rounded-xl border border-sidebar-border/70 p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-base font-medium">{{ day.date }}</h2>
                <p class="text-sm" :class="day.matches ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                    <template v-if="day.usage">
                        Pixelforge {{ day.usage.label }}
                        <span :title="day.usage.exact">· {{ day.matches ? 'Match' : 'Mismatch' }}</span>
                    </template>
                    <template v-else>No Pixelforge total for this day</template>
                </p>
            </div>

            <p class="text-sm text-muted-foreground">
                Credits <span :title="day.credits.exact">{{ day.credits.label }}</span>
                · Provider <span :title="day.provider_cost.exact">{{ day.provider_cost.label }}</span>
                · Profit <span :title="day.profit.exact">{{ day.profit.label }}</span>
            </p>

            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-1 font-medium">User</th>
                        <th class="py-1 font-medium">Credits</th>
                        <th class="py-1 font-medium">Provider</th>
                        <th class="py-1 font-medium">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in day.users" :key="user.name">
                        <td class="py-1">{{ user.name }}</td>
                        <td class="py-1" :title="user.credits.exact">{{ user.credits.label }}</td>
                        <td class="py-1" :title="user.provider_cost.exact">{{ user.provider_cost.label }}</td>
                        <td class="py-1" :title="user.profit.exact">{{ user.profit.label }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
