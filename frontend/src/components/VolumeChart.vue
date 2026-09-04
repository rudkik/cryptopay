<script setup lang="ts">
import { computed, ref } from 'vue'
import { Line } from 'vue-chartjs'
import {
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
  type ChartData,
  type ChartOptions,
  type ScriptableContext,
} from 'chart.js'
import type { DashboardChartPoint } from '@/api/types'
import { formatAmount, formatShortDate } from '@/utils/format'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip, Legend)

const props = defineProps<{ points: DashboardChartPoint[] }>()

const canvas = ref()

/** Light palette (SPEC §9): USDT rides the violet primary, USDC the teal accent. */
const SERIES = { usdt: '#6d4df2', usdc: '#0ea5a4' } as const
const INK = '#63616c'
const GRID = '#e5e2d9'
const SURFACE = '#ffffff'

function gradient(color: string) {
  return (context: ScriptableContext<'line'>) => {
    const { ctx, chartArea } = context.chart
    if (!chartArea) return 'transparent'
    const fill = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
    // Gentle wash on an off-white ground — a heavy fill muddies the grid.
    fill.addColorStop(0, `${color}2e`)
    fill.addColorStop(1, `${color}00`)
    return fill
  }
}

const chartData = computed<ChartData<'line'>>(() => ({
  labels: props.points.map((p) => formatShortDate(p.date)),
  datasets: [
    {
      label: 'USDT',
      data: props.points.map((p) => Number(p.USDT ?? 0)),
      borderColor: SERIES.usdt,
      backgroundColor: gradient(SERIES.usdt),
      borderWidth: 2,
      fill: true,
      tension: 0.35,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: SERIES.usdt,
      pointHoverBorderColor: SURFACE,
      pointHoverBorderWidth: 2,
    },
    {
      label: 'USDC',
      data: props.points.map((p) => Number(p.USDC ?? 0)),
      borderColor: SERIES.usdc,
      backgroundColor: gradient(SERIES.usdc),
      borderWidth: 2,
      fill: true,
      tension: 0.35,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: SERIES.usdc,
      pointHoverBorderColor: SURFACE,
      pointHoverBorderWidth: 2,
    },
  ],
}))

const chartOptions = computed<ChartOptions<'line'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: {
      display: true,
      align: 'end',
      labels: {
        color: INK,
        boxWidth: 8,
        boxHeight: 8,
        usePointStyle: true,
        pointStyle: 'circle',
        font: { family: 'Inter, sans-serif', size: 11 },
        padding: 16,
        /*
         * The dataset fill is a gradient callback that resolves to `transparent`
         * while the legend is drawn, which left hollow rings. Paint the legend
         * dots from the line colour instead.
         */
        generateLabels: (chart) =>
          chart.data.datasets.map((dataset, index) => ({
            text: String(dataset.label ?? ''),
            fillStyle: dataset.borderColor as string,
            strokeStyle: dataset.borderColor as string,
            lineWidth: 0,
            hidden: !chart.isDatasetVisible(index),
            datasetIndex: index,
          })),
      },
    },
    tooltip: {
      backgroundColor: SURFACE,
      borderColor: GRID,
      borderWidth: 1,
      titleColor: '#1c1b1f',
      bodyColor: '#1c1b1f',
      padding: 10,
      cornerRadius: 10,
      displayColors: true,
      usePointStyle: true,
      titleFont: { family: 'Inter, sans-serif', size: 12 },
      bodyFont: { family: 'JetBrains Mono, monospace', size: 12 },
      callbacks: {
        label: (item) => ` ${item.dataset.label}: ${formatAmount(String(item.parsed.y), { maxDecimals: 2 })}`,
      },
    },
  },
  scales: {
    x: {
      grid: { display: false },
      border: { display: false },
      ticks: {
        color: INK,
        font: { family: 'Inter, sans-serif', size: 10 },
        maxRotation: 0,
        autoSkipPadding: 24,
      },
    },
    y: {
      grid: { color: GRID },
      border: { display: false },
      ticks: {
        color: INK,
        font: { family: 'Inter, sans-serif', size: 10 },
        maxTicksLimit: 5,
        callback: (value) => formatAmount(String(value), { maxDecimals: 0 }),
      },
      beginAtZero: true,
    },
  },
}))
</script>

<template>
  <div class="h-64 w-full sm:h-72">
    <Line ref="canvas" :data="chartData" :options="chartOptions" aria-label="30-day settled volume" />
  </div>
</template>
