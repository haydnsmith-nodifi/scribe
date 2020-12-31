<?php

namespace Knuckles\Scribe\Extracting\Strategies\ResponseFields;

use Knuckles\Camel\Endpoint\EndpointData;
use Knuckles\Camel\Endpoint\Response;
use Knuckles\Camel\Endpoint\ResponseCollection;
use Knuckles\Scribe\Extracting\ParamHelpers;
use Knuckles\Scribe\Extracting\RouteDocBlocker;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Mpociot\Reflection\DocBlock\Tag;

class GetFromResponseFieldTag extends Strategy
{
    public string $stage = 'responseFields';

    use ParamHelpers;

    public function __invoke(EndpointData $endpointData, array $routeRules)
    {
        $methodDocBlock = RouteDocBlocker::getDocBlocksFromRoute($endpointData->route)['method'];

        return $this->getResponseFieldsFromDocBlock($methodDocBlock->getTags(), $endpointData->responses);
    }

    /**
     * @param Tag[] $tags
     * @param ResponseCollection|null $responses
     *
     * @return array
     */
    public function getResponseFieldsFromDocBlock(array $tags, ResponseCollection $responses = null): array
    {
        $parameters = collect($tags)
            ->filter(function ($tag) {
                return $tag instanceof Tag && $tag->getName() === 'responseField';
            })
            ->mapWithKeys(function (Tag $tag) use ($responses) {
                // Format:
                // @responseField <name> <type> <description>
                // Examples:
                // @responseField text string The text.
                // @responseField user_id integer The ID of the user.
                preg_match('/(.+?)\s+(.+?)\s+([\s\S]*)/', $tag->getContent(), $content);
                if (empty($content)) {
                    // this means only name and type were supplied
                    [$name, $type] = preg_split('/\s+/', $tag->getContent());
                    $description = '';
                } else {
                    [$_, $name, $type, $description] = $content;
                    $description = trim($description);
                }

                $type = $this->normalizeTypeName($type);

                // Support optional type in annotation
                if (!$this->isSupportedTypeInDocBlocks($type)) {
                    // Then that wasn't a type, but part of the description
                    $description = trim("$type $description");

                    // Try to get a type from first 2xx response
                    $validResponse = collect($responses ?: [])->first(function (Response $r) {
                        $status = intval($r->status);
                        return $status >= 200 && $status < 300;
                    });
                    $validResponseContent = json_decode($validResponse->content ?? null, true);
                    if (!$validResponseContent) {
                        $type = '';
                    } else {
                        $nonexistent = new \stdClass();
                        $value = $validResponseContent[$name]
                            ?? $validResponseContent['data'][$name] // Maybe it's a Laravel ApiResource
                            ?? $validResponseContent[0][$name] // Maybe it's a list
                            ?? $validResponseContent['data'][0][$name] // Maybe an Api Resource Collection?
                            ?? $nonexistent;
                        if ($value !== $nonexistent) {
                            $type =  $this->normalizeTypeName(gettype($value));
                        } else {
                            $type = '';
                        }
                    }
                }

                return [$name => compact('name', 'type', 'description')];
            })->toArray();

        return $parameters;
    }
}
