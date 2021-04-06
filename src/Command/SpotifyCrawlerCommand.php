<?php

declare(strict_types=1);

namespace App\Command;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

class SpotifyCrawlerCommand extends Command
{
    protected static $defaultName = "app:spotify:crawl";

    private $client;

    private string $mainApiUrl = "https://api-dot-new-spotifyjobs-com.nw.r.appspot.com/wp-json/animal/v1/job/search?l=stockholm";
    private string $jobUrl = "https://www.spotifyjobs.com/_next/data/|BUILD_ID|/jobs/|JOB_ID|.json";

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->client = $httpClient;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Spotify Crawler');
    }

    /**
     * @return string
     * @throws \JsonException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function getBuildId(): string
    {
        // get build id
        $response = $this->client->request("GET", "https://www.spotifyjobs.com/jobs");
        $firstPageContent = $response->getContent();

        // crawl DOM
        $crawler = new Crawler($firstPageContent);
        // find JSON script containing buildId
        $scriptContent = $crawler->filterXPath('//*[@id="__NEXT_DATA__"]')->text();
        $scriptJson = json_decode($scriptContent, true, 512, JSON_THROW_ON_ERROR);
        return $scriptJson['buildId'];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $buildId = $this->getBuildId();

        $apiResponse = $this->client->request("GET", $this->mainApiUrl);
        $apiResponseContent = $apiResponse->getContent();

        try {
            $apiContentJson = json_decode($apiResponseContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \Exception("Failed decoding API JSON");
            return Command::FAILURE;
        }

        $results = $apiContentJson['result'];

        foreach ($results as &$item) {

            $currentJobUrl = str_replace(array("|BUILD_ID|", "|JOB_ID|"), array($buildId, $item['id']), $this->jobUrl);

            $itemResponse = $this->client->request("GET", $currentJobUrl);
            try {
                $itemResponseContent = $itemResponse->getContent();
            } catch (\Exception $e) {
                // item is returning error, so we cant get enough data from it, remove it
                unset($item);
                continue;
            }
            $itemResponseJson = json_decode($itemResponseContent, true, 512, JSON_THROW_ON_ERROR);

            $urls = $itemResponseJson['pageProps']['job']['urls'];
            $headlinesWithText = $itemResponseJson['pageProps']['job']['content']['lists'];
            $description = $itemResponseJson['pageProps']['job']['content']['description'];

            $item['urls'] = $urls;
            $item['headlines'] = $headlinesWithText;
            $item['description'] = $description;

            $professionalMatch = false;

            foreach ($item['headlines'] as $headlineIndex => $headline) {
                // skip all other headlines
                if (stripos($headline['text'], 'Who you are') === false) {
                    continue;
                }

                $yearsOfExperience = "Not Set";
                preg_match('/(\d+\s?(?:-\d+)?\+?)\s*(years?)/', $headline['content'], $matches);
                // if group with years exist
                if (isset($matches[1])) {
                    $yearsOfExperience = $matches[1];
                }

                if (stripos($item['text'], 'professional experience') !== false) {
                    $professionalMatch = true;
                }

                $item['experience'] = $yearsOfExperience;
            }
            // proffesional or graduate
            if (stripos($item['text'], 'fullstack') !== false
                || stripos($item['text'], 'senior') !== false
                || stripos($item['text'], 'engineer') !== false
                || stripos($item['text'], 'lead') !== false
                || $yearsOfExperience !== "Not Set"
                || $professionalMatch === true) {
                $item['requirement'] = "Experienced";
            } else {
                $item['requirement'] = "Not Experienced";
            }

        }

        $filesystem = new Filesystem();

        try {

            $filesystem->dumpFile('final.json', json_encode($results, JSON_THROW_ON_ERROR));
        } catch (IOExceptionInterface $exception) {
            echo "An error occurred while creating file";
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}