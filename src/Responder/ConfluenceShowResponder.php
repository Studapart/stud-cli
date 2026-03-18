<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ConfluenceShowResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Content;
use App\View\DefinitionItem;
use App\View\PageViewConfig;
use App\View\Section;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfluenceShowResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger
    ) {
    }

    public function respond(SymfonyStyle $io, ConfluenceShowResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            if (! $response->isSuccess()) {
                return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
            }

            return new AgentJsonResponse(true, data: [
                'id' => $response->id,
                'title' => $response->title,
                'url' => $response->url,
                'body' => $response->body,
            ]);
        }

        $this->helper->initSection($this->logger, 'confluence.show.section', []);

        if (! $response->isSuccess()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $response->getError());

            return null;
        }

        $sections = $this->buildSections($response);
        $viewConfig = new PageViewConfig($sections, $this->helper->translator, $this->helper->colorHelper);
        $viewConfig->render([$response], $this->logger, []);

        return null;
    }

    /**
     * @return Section[]
     */
    protected function buildSections(ConfluenceShowResponse $response): array
    {
        $items = [
            new DefinitionItem('confluence.show.label_id', fn (ConfluenceShowResponse $dto): string => $dto->id ?? ''),
            new DefinitionItem('confluence.show.label_title', fn (ConfluenceShowResponse $dto): string => $dto->title ?? ''),
            new DefinitionItem('confluence.show.label_url', fn (ConfluenceShowResponse $dto): string => $dto->url ?? ''),
        ];
        $sections = [new Section('', $items)];

        if ($response->body !== null && $response->body !== '') {
            $contentTitle = $this->helper->translator->trans('confluence.show.section_content');
            $sections[] = new Section($contentTitle, [
                new Content(fn (ConfluenceShowResponse $dto): string => $dto->body ?? '', 'text'),
            ]);
        }

        return $sections;
    }
}
