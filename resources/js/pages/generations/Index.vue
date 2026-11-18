<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type Usage = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
};

type GenerationCard = {
    id: string;
    prompt: string;
    status: string;
    status_label: string;
    size: string;
    quality: string;
    estimate_minimum: string;
    estimate_maximum: string;
    estimate_minimum_exact: string;
    estimate_maximum_exact: string;
    actual: string | null;
    actual_exact: string | null;
    error_message: string | null;
    image_url: string | null;
    image_expired: boolean;
    usage: Usage | null;
    in_progress: boolean;
};

const props = defineProps<{
    generations: GenerationCard[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'History',
                href: '/generations',
            },
        ],
    },
});

const inProgress = computed(() => props.generations.some((generation) => generation.in_progress));

const { start, stop } = usePoll(3000, { only: ['generations'] }, { autoStart: false });

watch(inProgress, (active) => {
    if (active) {
        start();

        return;
    }

    stop();
}, { immediate: true });

const badgeClass = (status: string): string => {
    if (status === 'succeeded') {
        return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
    }

    if (status === 'failed') {
        return 'bg-red-500/15 text-red-700 dark:text-red-300';
    }

    if (status === 'processing') {
        return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
    }

    return 'bg-muted text-muted-foreground';
};
</script>

<template>
    <Head title="History" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-end justify-between gap-4">
            <Heading
                title="History"
                description="Estimated price first, then the actual charge once a generation finishes."
            />
            <Button as-child>
                <Link href="/generations/create">Generate</Link>
            </Button>
        </div>

        <p v-if="generations.length === 0" class="text-sm text-muted-foreground">
            No generations yet.
        </p>

        <div v-else class="grid gap-4">
            <article
                v-for="generation in generations"
                :key="generation.id"
                class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 md:grid-cols-[10rem_minmax(0,1fr)]"
            >
                <div class="flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-muted">
                    <img
                        v-if="generation.image_url"
                        :src="generation.image_url"
                        :alt="generation.prompt"
                        class="h-full w-full object-cover"
                    >
                    <span v-else-if="generation.image_expired" class="px-3 text-center text-sm text-muted-foreground">
                        Image expired
                    </span>
                    <span v-else class="px-3 text-center text-sm text-muted-foreground">
                        {{ generation.status_label }}
                    </span>
                </div>

                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass(generation.status)">
                            {{ generation.status_label }}
                        </span>
                        <span class="text-xs text-muted-foreground">{{ generation.quality }} · {{ generation.size }}</span>
                    </div>

                    <p class="text-sm">{{ generation.prompt }}</p>

                    <p class="text-sm">
                        <span class="text-muted-foreground">Estimate </span>
                        <span :title="`${generation.estimate_minimum_exact} – ${generation.estimate_maximum_exact}`">
                            {{ generation.estimate_minimum }} – {{ generation.estimate_maximum }}
                        </span>
                        <template v-if="generation.actual">
                            <span class="text-muted-foreground"> · Actual </span>
                            <span :title="generation.actual_exact ?? undefined">{{ generation.actual }}</span>
                        </template>
                    </p>

                    <p v-if="generation.status === 'failed'" class="text-sm text-red-600 dark:text-red-400">
                        {{ generation.error_message }}
                        <template v-if="!generation.error_message?.includes('You were not charged')">
                            You were not charged.
                        </template>
                    </p>

                    <p v-if="generation.usage" class="text-xs text-muted-foreground">
                        Text {{ generation.usage.input_text_tokens }}
                        · Image {{ generation.usage.input_image_tokens }}
                        · Output {{ generation.usage.output_tokens }}
                        · Reasoning {{ generation.usage.reasoning_tokens }}
                    </p>
                </div>
            </article>
        </div>
    </div>
</template>
