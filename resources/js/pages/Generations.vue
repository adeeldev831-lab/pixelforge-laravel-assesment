<script setup lang="ts">
import GenerationCard from '@/components/GenerationCard.vue';
import GenerationForm from '@/components/GenerationForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Generation } from '@/types';
import { Head, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    generations: Generation[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generations' }];

// Live status: while anything is still running, reload just the list every two seconds.
// It reads our own database, so it costs nothing against Pixelforge's rate limit.
const { start, stop } = usePoll(2000, { only: ['generations'] }, { autoStart: false });

watch(
    () => props.generations.some((generation) => !['succeeded', 'failed'].includes(generation.status)),
    (anyRunning) => (anyRunning ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid gap-8 p-4 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            <section>
                <h2 class="mb-4 text-lg font-semibold">New image</h2>
                <GenerationForm :sizes="sizes" :qualities="qualities" />
            </section>

            <section>
                <h2 class="mb-4 text-lg font-semibold">Your generations</h2>

                <div v-if="generations.length" class="space-y-3">
                    <GenerationCard v-for="generation in generations" :key="generation.id" :generation="generation" />
                </div>
                <p v-else class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Nothing here yet. Your images will appear here as soon as you generate one.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
