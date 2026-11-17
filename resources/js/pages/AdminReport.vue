<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatUsd } from '@/lib/money';
import { type BreadcrumbItem, type DailyReportRow, type ReportRow, type UserReportRow } from '@/types';
import { Head } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from 'lucide-vue-next';

defineProps<{
    startDate: string;
    endDate: string;
    total: ReportRow;
    days: DailyReportRow[];
    userDays: UserReportRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 p-4">
            <p class="text-sm text-muted-foreground">
                {{ startDate }} to {{ endDate }}, by UTC day. A generation counts on the day Pixelforge finished it, which is the day Pixelforge bills
                it. Failed generations cost nothing and are left out.
            </p>

            <dl class="grid gap-4 sm:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Generations</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ total.generations }}</dd>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Credit spend</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ formatUsd(total.charged) }}</dd>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Provider cost</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ formatUsd(total.provider_cost) }}</dd>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Profit</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ formatUsd(total.profit) }}</dd>
                </div>
            </dl>

            <section>
                <h2 class="mb-3 text-lg font-semibold">By day</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm tabular-nums">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day (UTC)</th>
                                <th class="px-4 py-2 text-right font-medium">Generations</th>
                                <th class="px-4 py-2 text-right font-medium">Credit spend</th>
                                <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                                <th class="px-4 py-2 text-right font-medium">Pixelforge reports</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in days" :key="day.date" class="border-t">
                                <td class="px-4 py-2">{{ day.date }}</td>
                                <td class="px-4 py-2 text-right">{{ day.generations }}</td>
                                <td class="px-4 py-2 text-right">{{ formatUsd(day.charged) }}</td>
                                <td class="px-4 py-2 text-right">{{ formatUsd(day.provider_cost) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <span v-if="day.pixelforge_cost === null" class="text-muted-foreground">Unavailable</span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1"
                                        :class="day.matches_pixelforge ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'"
                                    >
                                        <component :is="day.matches_pixelforge ? CircleCheck : CircleAlert" class="h-4 w-4" />
                                        {{ formatUsd(day.pixelforge_cost) }}
                                        <span class="sr-only">{{ day.matches_pixelforge ? 'matches' : 'does not match' }}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right">{{ formatUsd(day.profit) }}</td>
                            </tr>
                            <tr v-if="!days.length" class="border-t">
                                <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">No succeeded generations in this period.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-lg font-semibold">By day and user</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm tabular-nums">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day (UTC)</th>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 text-right font-medium">Generations</th>
                                <th class="px-4 py-2 text-right font-medium">Credit spend</th>
                                <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in userDays" :key="`${row.date} ${row.user}`" class="border-t">
                                <td class="px-4 py-2">{{ row.date }}</td>
                                <td class="px-4 py-2">{{ row.user }}</td>
                                <td class="px-4 py-2 text-right">{{ row.generations }}</td>
                                <td class="px-4 py-2 text-right">{{ formatUsd(row.charged) }}</td>
                                <td class="px-4 py-2 text-right">{{ formatUsd(row.provider_cost) }}</td>
                                <td class="px-4 py-2 text-right">{{ formatUsd(row.profit) }}</td>
                            </tr>
                            <tr v-if="!userDays.length" class="border-t">
                                <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">No succeeded generations in this period.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
