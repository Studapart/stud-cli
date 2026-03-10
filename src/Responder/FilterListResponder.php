<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\FilterListResponse;
use App\Service\DtoSerializer;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListResponder
{
    private readonly DtoSerializer $serializer;

    public function __construct(
        private readonly ResponderHelper $helper,
        ?DtoSerializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new DtoSerializer();
    }

    public function respond(SymfonyStyle $io, FilterListResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $this->helper->initSection($io, 'filter.list.section');

        if (empty($response->filters)) {
            $io->note($this->helper->translator->trans('filter.list.no_filters'));

            return null;
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '',
                [
                    new TableBlock([
                        new Column('name', 'table.name', fn ($item) => $item->name),
                        new Column('description', 'table.description', fn ($item) => $item->description ?? ''),
                    ]),
                ]
            ),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($response->filters, $io);

        return null;
    }

    protected function respondJson(FilterListResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        return new AgentJsonResponse(true, data: [
            'filters' => $this->serializer->serializeList($response->filters),
        ]);
    }
}
