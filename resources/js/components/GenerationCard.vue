<script setup lang="ts">
import { formatUsd } from '@/lib/money';
import { type Generation, type GenerationStatus } from '@/types';
import { CircleAlert, CircleCheck, ImageOff, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    generation: Generation;
}>();

const labels: Record<GenerationStatus, string> = {
    submitting: 'Sending to Pixelforge',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const isActive = computed(() => !['succeeded', 'failed'].includes(props.generation.status));

// Pixelforge's image links stop working 24 hours after the generation finished.
const imageUnavailable = ref(false);
</script>

<template>
    <article class="flex gap-4 rounded-xl border bg-card p-4">
        <div class="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-muted-foreground">
            <a v-if="generation.image_url && !imageUnavailable" :href="generation.image_url" target="_blank" rel="noopener">
                <img :src="generation.image_url" :alt="generation.prompt" class="h-28 w-28 object-cover" @error="imageUnavailable = true" />
            </a>
            <div v-else-if="generation.status === 'succeeded'" class="px-2 text-center text-xs">
                <ImageOff class="mx-auto mb-1 h-5 w-5" />
                Image expired
            </div>
            <LoaderCircle v-else-if="isActive" class="h-6 w-6 animate-spin" />
            <CircleAlert v-else class="h-6 w-6" />
        </div>

        <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                <span
                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium"
                    :class="{
                        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200': isActive,
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200': generation.status === 'succeeded',
                        'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200': generation.status === 'failed',
                    }"
                >
                    <LoaderCircle v-if="isActive" class="h-3 w-3 animate-spin" />
                    <CircleCheck v-else-if="generation.status === 'succeeded'" class="h-3 w-3" />
                    <CircleAlert v-else class="h-3 w-3" />
                    {{ labels[generation.status] }}
                </span>
                <span>{{ generation.size.replace('x', ' × ') }}</span>
                <span class="capitalize">{{ generation.quality }} quality</span>
                <span v-if="generation.reference_images.length">
                    {{ generation.reference_images.length }} reference {{ generation.reference_images.length === 1 ? 'image' : 'images' }}
                </span>
                <time :datetime="generation.created_at">{{ new Date(generation.created_at).toLocaleString() }}</time>
            </div>

            <p class="line-clamp-3 break-words text-sm">{{ generation.prompt }}</p>

            <p v-if="generation.status === 'failed'" class="text-sm text-red-700 dark:text-red-300">
                {{ generation.error_message }}
            </p>

            <dl class="flex flex-wrap gap-x-8 gap-y-1 text-sm tabular-nums">
                <div>
                    <dt class="text-xs text-muted-foreground">Estimated</dt>
                    <dd>{{ formatUsd(generation.estimated_min) }} to {{ formatUsd(generation.estimated_max) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Actual cost</dt>
                    <dd v-if="generation.status === 'succeeded' && generation.charged" class="font-semibold">
                        {{ formatUsd(generation.charged) }}
                    </dd>
                    <dd v-else-if="generation.status === 'failed'" class="font-semibold">$0.00 <span class="font-normal">(not charged)</span></dd>
                    <dd v-else class="text-muted-foreground">Known when it finishes</dd>
                </div>
            </dl>
        </div>
    </article>
</template>
