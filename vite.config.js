import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                "resources/css/app.css",
                "resources/js/app.js",
                "resources/js/drawing-board.js",
                "resources/js/planning.js",
                "resources/js/planning-weeks.js",
                "resources/js/project-list.js",
                "resources/js/winkel-preferred-worker.js",
                "resources/js/shop-activities.js",
                "resources/js/measurement-form.js",
                "resources/js/project-upload.js",
                "resources/js/calculation-create.js",
                "resources/js/area-without-m2-create.js",
                "resources/js/calculation-import-progress.js",
                "resources/js/calculation-board.js",
                "resources/js/calculation-print-dialog.js",
                "resources/js/calculation-print.js",
                "resources/js/snag-pdf.js",
                "resources/js/vakman-hours.js",
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
