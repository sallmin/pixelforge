<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    balance: {
        label: string;
        exact: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Credits',
                href: '/credits',
            },
        ],
    },
});
</script>

<template>
    <Head title="Credits" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading title="Credits" description="Buy credits, then spend them on generations. This is not a real payment." />

        <p class="text-lg font-semibold" :title="balance.exact">{{ balance.label }}</p>

        <Form action="/credits" method="post" class="space-y-4" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="amount">Amount in dollars</Label>
                <Input id="amount" name="amount" type="text" inputmode="decimal" placeholder="10.00" required />
                <InputError :message="errors.amount" />
            </div>
            <Button type="submit" :disabled="processing">Buy credits</Button>
        </Form>
    </div>
</template>
