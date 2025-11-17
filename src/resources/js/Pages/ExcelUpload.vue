<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

interface Transaction {
    [key: string]: string | null;
}

interface ProcessedData {
    success: boolean;
    message: string;
    data?: {
        file_info: {
            total_rows: number;
            header_lines_skipped: number;
            data_rows_processed: number;
        };
        total_transactions: number;
        headers: string[];
        transactions: Transaction[];
    };
    error?: string;
}

const selectedFile = ref<File | null>(null);
const headerLines = ref<number>(1);
const format = ref<string>('auto');
const isProcessing = ref<boolean>(false);
const processedData = ref<ProcessedData | null>(null);
const errorMessage = ref<string>('');

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        selectedFile.value = target.files[0];
        processedData.value = null;
        errorMessage.value = '';
    }
};

const processFile = async () => {
    if (!selectedFile.value) {
        errorMessage.value = 'Please select a file first';
        return;
    }

    isProcessing.value = true;
    errorMessage.value = '';
    processedData.value = null;

    try {
        const formData = new FormData();
        formData.append('excel_file', selectedFile.value);
        formData.append('header_lines', headerLines.value.toString());
        formData.append('format', format.value);

        const response = await axios.post<ProcessedData>(
            '/api/excel/process-transactions',
            formData,
            {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }
        );

        processedData.value = response.data;
    } catch (error: any) {
        if (error.response?.data) {
            errorMessage.value = error.response.data.message || 'An error occurred while processing the file';
        } else {
            errorMessage.value = 'Network error: Could not connect to the server';
        }
        console.error('Error processing file:', error);
    } finally {
        isProcessing.value = false;
    }
};

const resetForm = () => {
    selectedFile.value = null;
    headerLines.value = 1;
    format.value = 'auto';
    processedData.value = null;
    errorMessage.value = '';
    const fileInput = document.getElementById('file-upload') as HTMLInputElement;
    if (fileInput) fileInput.value = '';
};
</script>

<template>
    <Head title="Excel Upload" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2
                    class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
                >
                    Excel Upload
                </h2>
                <Link
                    :href="route('dashboard')"
                    class="inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    ← Back to Dashboard
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Upload Section -->
                <div
                    class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800"
                >
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Upload Excel File
                        </h3>

                        <div class="space-y-4">
                            <!-- File Input -->
                            <div>
                                <label
                                    for="file-upload"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"
                                >
                                    Select File
                                </label>
                                <input
                                    id="file-upload"
                                    type="file"
                                    accept=".xlsx,.xls,.csv,.html"
                                    @change="handleFileChange"
                                    class="block w-full text-sm text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer bg-gray-50 dark:bg-gray-700 focus:outline-none"
                                />
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Supported formats: .xlsx, .xls, .csv, .html (Max: 10MB)
                                </p>
                            </div>

                            <!-- Header Lines Input -->
                            <div>
                                <label
                                    for="header-lines"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"
                                >
                                    Header Lines to Skip
                                </label>
                                <input
                                    id="header-lines"
                                    v-model.number="headerLines"
                                    type="number"
                                    min="0"
                                    max="50"
                                    class="block w-full rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                />
                            </div>

                            <!-- Format Selection -->
                            <div>
                                <label
                                    for="format"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"
                                >
                                    File Format
                                </label>
                                <select
                                    id="format"
                                    v-model="format"
                                    class="block w-full rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                >
                                    <option value="auto">Auto Detect</option>
                                    <option value="html">HTML Table</option>
                                </select>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-3">
                                <button
                                    @click="processFile"
                                    :disabled="!selectedFile || isProcessing"
                                    class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <span v-if="isProcessing">Processing...</span>
                                    <span v-else>Process File</span>
                                </button>
                                <button
                                    @click="resetForm"
                                    type="button"
                                    class="inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    Reset
                                </button>
                            </div>

                            <!-- Error Message -->
                            <div
                                v-if="errorMessage"
                                class="rounded-md bg-red-50 dark:bg-red-900/20 p-4"
                            >
                                <p class="text-sm text-red-800 dark:text-red-200">
                                    {{ errorMessage }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Results Section -->
                <div
                    v-if="processedData?.success"
                    class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800"
                >
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Processed Data
                        </h3>

                        <!-- File Info -->
                        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-700 p-4">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Total Rows</p>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                    {{ processedData.data?.file_info.total_rows }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-700 p-4">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Header Lines Skipped</p>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                    {{ processedData.data?.file_info.header_lines_skipped }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-700 p-4">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Total Transactions</p>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                    {{ processedData.data?.total_transactions }}
                                </p>
                            </div>
                        </div>

                        <!-- Transactions Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th
                                            v-for="header in processedData.data?.headers"
                                            :key="header"
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            {{ header }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                    <tr
                                        v-for="(transaction, index) in processedData.data?.transactions"
                                        :key="index"
                                        class="hover:bg-gray-50 dark:hover:bg-gray-700"
                                    >
                                        <td
                                            v-for="header in processedData.data?.headers"
                                            :key="header"
                                            class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100"
                                        >
                                            {{ transaction[header] ?? '-' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
