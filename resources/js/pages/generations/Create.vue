<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generate',
                href: '/generations/create',
            },
        ],
    },
});

defineProps<{
    balance: {
        label: string;
        exact: string;
    };
}>();

const prompt = ref('');
const size = ref('1024x1024');
const quality = ref('medium');
const references = ref(['', '', '', '']);

const quote = ref<{
    minimum: string;
    maximum: string;
    minimum_exact: string;
    maximum_exact: string;
    reason: string;
} | null>(null);

const estimateError = ref('');

const characters = computed(() => Array.from(prompt.value).length);

let timer: ReturnType<typeof setTimeout> | undefined;

async function loadEstimate(): Promise<void> {
    const params = new URLSearchParams({
        prompt: prompt.value,
        size: size.value,
        quality: quality.value,
    });

    references.value.filter((url) => url.trim() !== '').forEach((url) => {
        params.append('reference_images[]', url);
    });

    const response = await fetch(`/generations/estimate?${params.toString()}`, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        estimateError.value = 'Pricing is temporarily unavailable.';

        return;
    }

    quote.value = await response.json();
    estimateError.value = '';
}

function scheduleEstimate(): void {
    clearTimeout(timer);
    timer = setTimeout(() => {
        void loadEstimate();
    }, 300);
}

watch([prompt, size, quality, references], scheduleEstimate, { deep: true });

onMounted(() => {
    void loadEstimate();
});
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-end justify-between gap-4">
            <Heading
                title="Generate"
                description="Write a prompt, then check the price range before you submit."
            />
            <div class="flex items-center gap-2">
                <Button variant="outline" as-child>
                    <Link href="/credits" :title="balance.exact">{{ balance.label }}</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link href="/generations">History</Link>
                </Button>
            </div>
        </div>

        <Form
            action="/generations"
            method="post"
            class="max-w-3xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="space-y-6">
                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span class="text-sm text-muted-foreground">{{ characters }} / 4000</span>
                    </div>
                    <textarea
                        id="prompt"
                        name="prompt"
                        v-model="prompt"
                        required
                        maxlength="4000"
                        rows="6"
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                    />
                    <InputError :message="errors.prompt" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="size">Size</Label>
                        <select
                            id="size"
                            name="size"
                            v-model="size"
                            class="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                        >
                            <option value="1024x1024">1024 × 1024</option>
                            <option value="1536x1024">1536 × 1024</option>
                            <option value="1024x1536">1024 × 1536</option>
                        </select>
                        <InputError :message="errors.size" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quality">Quality</Label>
                        <select
                            id="quality"
                            name="quality"
                            v-model="quality"
                            class="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                        >
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                        <InputError :message="errors.quality" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Reference images</Label>
                    <Input
                        v-for="index in 4"
                        :key="index"
                        v-model="references[index - 1]"
                        :name="`reference_images[]`"
                        type="url"
                        :placeholder="`https://example.com/reference-${index}.png`"
                    />
                    <p class="text-sm text-muted-foreground">Up to 4 http or https URLs. Each one adds a fixed image-token cost.</p>
                    <InputError :message="errors.reference_images" />
                </div>

                <aside class="rounded-xl border border-sidebar-border/70 p-4">
                    <h2 class="text-sm font-medium">Estimated cost</h2>
                    <p
                        v-if="quote"
                        class="mt-2 text-lg font-semibold"
                        :title="`${quote.minimum_exact} – ${quote.maximum_exact}`"
                    >
                        {{ quote.minimum }} – {{ quote.maximum }}
                    </p>
                    <p v-else class="mt-2 text-sm text-muted-foreground">Calculating…</p>
                    <p v-if="quote" class="mt-2 text-sm text-muted-foreground">{{ quote.reason }}</p>
                    <p v-if="estimateError" class="mt-2 text-sm text-red-600 dark:text-red-500">{{ estimateError }}</p>
                </aside>

                <InputError :message="errors.balance" />
                <Button type="submit" :disabled="processing">Generate</Button>
            </div>
        </Form>
    </div>
</template>
