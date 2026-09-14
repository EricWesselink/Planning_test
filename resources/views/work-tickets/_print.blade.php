<style>
    @media print {
        @page { margin: 0; }
        html, body { margin: 0 !important; }
        .ticket-page,
        .drawing-page {
            padding: 12mm 12mm 14mm;
        }
    }
</style>
<script>
    function niconPrintTicket() {
        const title = document.title;
        document.title = '';
        const restore = () => {
            document.title = title;
            window.removeEventListener('afterprint', restore);
        };
        window.addEventListener('afterprint', restore);
        window.print();
    }
</script>
