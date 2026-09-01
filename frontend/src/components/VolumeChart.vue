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

function gradient(color: string) {
  return (context: ScriptableContext<'line'>) => {
    const { ctx, chartArea } = context.chart
    if (!chartArea) return 'transparent'
    const fill = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
    fill.addColorStop(0, `${color}59`)
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
      borderColor: '#8b5cf6',
      backgroundColor: gradient('#8b5cf6'),
      borderWidth: 2,
      fill: true,
      tension: 0.35,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: '#8b5cf6',
      pointHoverBorderColor: '#0a0613',
      pointHoverBorderWidth: 2,
    },
    {
      label: 'USDC',
      data: props.points.map((p) => Number(p.USDC ?? 0)),
      borderColor: '#d946ef',
      backgroundColor: gradient('#d946ef'),
      borderWidth: 2,
      fill: true,
      tension: 0.35,
      pointRadius: 0,
      pointHoverRadius: 4,
      pointHoverBackgroundColor: '#d946ef',
      pointHoverBorderColor: '#0a0613',
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
        color: '#9d94b8',
        boxWidth: 8,
        boxHeight: 8,
        usePointStyle: true,
        pointStyle: 'circle',
        font: { family: 'Inter, sans-serif', size: 11 },
        padding: 16,
      },
    },
    tooltip: {
      backgroundColor: '#1e1438',
      borderColor: '#2d2050',
      borderWidth: 1,
      titleColor: '#ece8f6',
      bodyColor: '#ece8f6',
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
        color: '#9d94b8',
        font: { family: 'Inter, sans-serif', size: 10 },
        maxRotation: 0,
        autoSkipPadding: 24,
      },
    },
    y: {
      grid: { color: 'rgba(45,32,80,.55)' },
      border: { display: false },
      ticks: {
        color: '#9d94b8',
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
