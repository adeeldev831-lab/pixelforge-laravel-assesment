<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsd } from '@/lib/money';
import { type CostEstimate } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { LoaderCircle, Plus, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
}>();

const MAX_PROMPT_LENGTH = 4000;
const MAX_REFERENCE_IMAGES = 4;

const form = useForm({
    prompt: '',
    size: props.sizes[0],
    quality: props.qualities.includes('medium') ? 'medium' : props.qualities[0],
    reference_images: [] as string[],
});

const estimate = ref<CostEstimate | null>(null);
let latestEstimateRequest = 0;

// The server prices the form as it is typed, with the same code that prices it on submit.
const refreshEstimate = useDebounceFn(async () => {
    const request = ++latestEstimateRequest;
    let result: CostEstimate | null = null;

    if (form.prompt.trim() !== '') {
        try {
            result = (await axios.post<CostEstimate>(route('generations.estimate'), form.data())).data;
        } catch {
            // The form isn't valid yet (for example a half-typed URL), so there is no price to show.
        }
    }

    // A slower, older request must not overwrite a newer answer.
    if (request === latestEstimateRequest) {
        estimate.value = result;
    }
}, 300);

watch(() => form.data(), refreshEstimate, { deep: true });

const submit = () => {
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt'),
    });
};
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span class="text-xs text-muted-foreground">{{ form.prompt.length }} / {{ MAX_PROMPT_LENGTH }}</span>
            </div>
            <textarea
                id="prompt"
                v-model="form.prompt"
                rows="4"
                required
                :maxlength="MAX_PROMPT_LENGTH"
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            />
            <InputError :message="form.errors.prompt" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="size">Size</Label>
                <select id="size" v-model="form.size" class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm">
                    <option v-for="size in sizes" :key="size" :value="size">{{ size.replace('x', ' × ') }}</option>
                </select>
                <InputError :message="form.errors.size" />
            </div>

            <div class="grid gap-2">
                <Label for="quality">Quality</Label>
                <select
                    id="quality"
                    v-model="form.quality"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm capitalize shadow-sm"
                >
                    <option v-for="quality in qualities" :key="quality" :value="quality">{{ quality }}</option>
                </select>
                <InputError :message="form.errors.quality" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label
                >Reference images <span class="font-normal text-muted-foreground">(optional, up to {{ MAX_REFERENCE_IMAGES }})</span></Label
            >

            <div v-for="(_, index) in form.reference_images" :key="index" class="grid gap-1">
                <div class="flex gap-2">
                    <Input
                        v-model="form.reference_images[index]"
                        type="url"
                        placeholder="https://example.com/style.png"
                        :aria-label="`Reference image URL ${index + 1}`"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Remove reference image"
                        @click="form.reference_images.splice(index, 1)"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                </div>
                <InputError :message="form.errors[`reference_images.${index}`]" />
            </div>

            <div>
                <Button
                    v-if="form.reference_images.length < MAX_REFERENCE_IMAGES"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="form.reference_images.push('')"
                >
                    <Plus class="h-4 w-4" /> Add image URL
                </Button>
            </div>
            <InputError :message="form.errors.reference_images" />
        </div>

        <div class="rounded-lg border bg-muted/40 p-4 text-sm" aria-live="polite">
            <template v-if="estimate">
                <p class="text-muted-foreground">Estimated cost</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ formatUsd(estimate.min) }} <span class="text-muted-foreground">to</span> {{ formatUsd(estimate.max) }}
                </p>
                <p class="mt-2 text-muted-foreground">
                    The exact price depends on how much work the image takes, and is only known once it is ready. You pay the exact price, never more
                    than {{ formatUsd(estimate.max) }}. Failed generations are free.
                </p>
            </template>
            <p v-else class="text-muted-foreground">Write a prompt to see what it will cost.</p>
        </div>

        <Button type="submit" :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Generate image
        </Button>
    </form>
</template>
