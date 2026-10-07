<!doctype html>

<html lang="en">
<head>
    <meta charset="utf-8">
    <title>utopia-php/database</title>
    <meta name="description" content="utopia-php/database">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/vue@next"></script>
</head>

<body>
    <div class="chartcontainer">
        <canvas id="radarchart"></canvas>
    </div>
    <div id="datatables" class="datatables">
        <table v-for="n in queries.length" :key="n">
            <tr>
                <!-- v-for is base 1 index  -->
                <th colspan="6">{{ queries[n-1] }}</th>
            </tr>
            <tr>
                <th></th>
                <th v-for="set in results[0].data" :key="set.roles">
                    {{ set.roles }} {{ set.roles === 1 ? 'role' : 'roles' }}
                </th>
            </tr>
            <tr v-for="(result, index) in results" :key="result.name" v-bind:style="{ backgroundColor: colors[index].table }">
                <!-- grab just the timestamp from result.name -->
                <td> {{ result.name.split("_")[3] }}</td>
                <td v-for="set in result.data">{{ set.results[n-1].toFixed(4) }} s</td>
            </tr>
        </table>
    </div>

<script>

const results = <?php

$directory = './results';
$scanned_directory = \array_diff(\scandir($directory), array('..', '.'));
$results = [];
foreach ($scanned_directory as $path) {
    $results[] = [
        'name' => $path,
        'data' => \json_decode(\file_get_contents("{$directory}/{$path}"), true)
    ];
}

echo \json_encode($results);
?>;

console.info('Loaded results:', results);

const colors = [
    {
        line: "rgba(0, 184, 148, 1.0)",
        fill: "rgba(0, 184, 148, 0.2)",
        table: "rgba(0, 184, 148, 0.4)",
    },
    {
        line: "rgba(214, 48, 49, 1.0)",
        fill: "rgba(214, 48, 49, 0.2)",
        table: "rgba(214, 48, 49, 0.4)",
    },
    {
        line: "rgba(9, 132, 227, 1.0)",
        fill: "rgba(9, 132, 227, 0.2)",
        table: "rgba(9, 132, 227, 0.4)",
    },
    {
        line: "rgba(95, 39, 205, 1.0)",
        fill: "rgba(95, 39, 205, 0.2)",
        table: "rgba(95, 39, 205, 0.4)",
    },
    {
        line: "rgba(34, 47, 62, 1.0)",
        fill: "rgba(34, 47, 62, 0.2)",
        table: "rgba(34, 47, 62, 0.4)",
    },
    {
        line: "rgba(243, 104, 224, 1.0)",
        fill: "rgba(243, 104, 224, 0.2)",
        table: "rgba(243, 104, 224, 0.4)"
    },
    {
        line: "rgba(255, 159, 67, 1.0)",
        fill: "rgba(255, 159, 67, 0.2)",
        table: "rgba(255, 159, 67, 0.4)",
    },
];

// Radar chart
let datasets = [];
for (let i = 0; i < results.length; i++) {
    datasets[i] = {
        label: results[i].name,
        data: results[i].data[0].results,
        fill: true,
        backgroundColor: colors[i].fill,
        borderColor: colors[i].line,
        pointBackgroundColor: colors[i].line,
        pointBorderColor: '#fff',
        pointHoverBackgroundColor: '#fff',
        pointHoverBorderColor: colors[i].line,
    };
}

const chartData = {
    labels: [
        'created.greater(), genre.equal()',
        'genre.equal(OR)',
        'views.greater()',
        'text.search()',
    ],
    datasets: datasets,
};

const config = {
  type: 'radar',
  data: chartData,
  options: {
    elements: {
      line: {
        borderWidth: 2,
      },
    },
    responsive: true,
    maintainAspectRatio: false,
  },
};

const myChart = new Chart(
    document.getElementById('radarchart'),
    config
);

// datatables with vue
const datatables = {
    data() {
        return {
            results: results,
            queries: chartData.labels,
            colors: colors,
        };
    },
};

Vue.createApp(datatables).mount('#datatables');

</script>

<style>
table {
    margin: 1em;
}

table td {
    width: 110px;
    text-align: center;
}

.chartcontainer {
    width: 650px;
    height: 550px;
    margin: auto;
}

.datatables {
    display: flex;
    flex-flow: row wrap;
    justify-content: center;
    padding-left: 2em;
    padding-right: 2em;
}
</style>

</body>
</html>
