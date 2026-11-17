<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { formatUsd } from '@/lib/money';
import { type Credits } from '@/types';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    credits: Credits;
    packs: number[];
}>();

const buying = ref(false);

const buy = (dollars: number) => {
    router.post(
        route('credits.store'),
        { dollars },
        {
            preserveScroll: true,
            onStart: () => (buying.value = true),
            onFinish: () => (buying.value = false),
        },
    );
};
</script>

<template>
    <div class="rounded-xl border bg-card p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm text-muted-foreground">Available credits</p>
                <p class="text-2xl font-semibold tabular-nums">{{ formatUsd(credits.available) }}</p>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-sm text-muted-foreground">Buy credits</span>
                <Button v-for="dollars in packs" :key="dollars" variant="outline" size="sm" :disabled="buying" @click="buy(dollars)">
                    ${{ dollars }}
                </Button>
            </div>
        </div>

        <p class="mt-2 text-xs text-muted-foreground">
            Balance {{ formatUsd(credits.balance) }}, with {{ formatUsd(credits.reserved) }} held for images that are still generating. This is a
            demo: buying credits takes no payment.
        </p>
    </div>
</template>
