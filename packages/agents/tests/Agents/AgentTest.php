<?php

namespace Tests\Utopia\Agents;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\Ollama;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Agent;

class AgentTest extends TestCase
{
    private Agent $agent;

    private Adapter $mockAdapter;

    protected function setUp(): void
    {
        // Create a mock adapter
        $this->mockAdapter = new OpenAI('test-api-key', OpenAI::MODEL_O3_MINI);
        $this->agent = new Agent($this->mockAdapter);
    }

    public function testConstructor(): void
    {
        $this->assertSame($this->mockAdapter, $this->agent->getAdapter());
        $this->assertSame('', $this->agent->getDescription());
        $this->assertSame([], $this->agent->getInstructions());
    }

    public function testSetDescription(): void
    {
        $description = 'Test agent description';

        $result = $this->agent->setDescription($description);

        $this->assertSame($this->agent, $result);
        $this->assertSame($description, $this->agent->getDescription());
    }

    public function testSetInstructions(): void
    {
        $instructions = [
            'Instruction 1' => 'This is instruction 1',
            'Instruction 2' => 'This is instruction 2',
        ];

        $result = $this->agent->setInstructions($instructions);

        $this->assertSame($this->agent, $result);
        $this->assertSame($instructions, $this->agent->getInstructions());
    }

    public function testAddInstruction(): void
    {
        // Test adding a single instruction
        $result = $this->agent->addInstruction('Instruction 1', 'This is instruction 1');
        $this->assertSame($this->agent, $result);
        $this->assertSame([
            'Instruction 1' => 'This is instruction 1',
        ], $this->agent->getInstructions());

        // Test adding a duplicate instruction (should update the content)
        $this->agent->addInstruction('Instruction 1', 'Updated content');
        $this->assertSame([
            'Instruction 1' => 'Updated content',
        ], $this->agent->getInstructions());

        // Test adding a second instruction
        $this->agent->addInstruction('Instruction 2', 'This is instruction 2');
        $this->assertSame([
            'Instruction 1' => 'Updated content',
            'Instruction 2' => 'This is instruction 2',
        ], $this->agent->getInstructions());
    }

    public function testFluentInterface(): void
    {
        $description = 'Test Description';
        $instructions = [
            'Instruction 1' => 'Content 1',
            'Instruction 2' => 'Content 2',
        ];

        $result = $this->agent
            ->setDescription($description)
            ->setInstructions($instructions)
            ->addInstruction('Instruction 3', 'Content 3');

        $this->assertSame($this->agent, $result);
        $this->assertSame($description, $this->agent->getDescription());
        $this->assertSame([
            'Instruction 1' => 'Content 1',
            'Instruction 2' => 'Content 2',
            'Instruction 3' => 'Content 3',
        ], $this->agent->getInstructions());
    }

    public function testEmbedReturnsArrayWithEmbeddingAdapter(): void
    {
        $ollama = new Ollama();
        $ollama->setTimeout(180004);
        $agent = new Agent($ollama);
        $result = $agent->embed('Lorem ipsum dolor sit amet consectetur adipisicing elit. Cumque neque repudiandae facere sapiente quis eum ipsa eius dignissimos esse labore. Eaque impedit dignissimos atque distinctio error temporibus nam, praesentium magni quia harum amet et nesciunt, quae fugiat, nemo asperiores culpa? Blanditiis voluptate ullam necessitatibus voluptates quaerat vel nam consequuntur neque, reiciendis facilis error, optio molestiae illo impedit, molestias magnam adipisci laboriosam nesciunt distinctio. Harum iure tempora, deleniti libero asperiores quibusdam ipsam error dolorum natus, saepe exercitationem maxime eligendi tenetur sint debitis distinctio eaque facere nobis voluptas non delectus officia blanditiis suscipit in. Architecto maiores ab mollitia beatae ad, corrupti nobis, animi eligendi labore recusandae corporis. Aspernatur repellat ex tempore minus obcaecati laborum consectetur velit officia doloremque! Reiciendis expedita, repudiandae velit consectetur ex voluptatum quae quia nobis dignissimos exercitationem, quam voluptatibus ipsam laboriosam quod. Molestias ex ea minima maiores rerum nulla sit! Impedit, excepturi quod nulla alias quos incidunt, ea est nam autem placeat eaque nobis similique maiores? Optio veniam velit provident nihil non laboriosam, nisi saepe nesciunt quisquam cumque rerum reprehenderit obcaecati beatae expedita sit enim eveniet dolorum itaque labore eligendi cupiditate tenetur atque aut incidunt. Nam distinctio animi commodi dolor maiores repellat nemo molestiae adipisci quae temporibus quos reiciendis odit aperiam quod, alias soluta saepe praesentium tempora eaque expedita delectus blanditiis! Unde consectetur ipsum repellat aliquid impedit et eum, delectus porro ipsa eos expedita, labore, sint consequatur voluptates dolor quis molestiae quo? Labore placeat molestias illum ipsum consequuntur autem minus. Modi, deserunt. Suscipit quasi eaque vitae facilis veritatis, culpa magnam possimus eius enim excepturi quibusdam facere fugiat aliquid dignissimos sequi iusto maxime perferendis distinctio repudiandae doloribus. Ipsam distinctio, nihil error maxime iste eligendi corrupti voluptas animi fuga, incidunt recusandae illum voluptates totam atque, numquam ducimus vero unde quasi aut quis tempora. Excepturi vel a cupiditate similique eveniet, quia nihil. Eaque, natus consequatur. Eveniet incidunt alias cupiditate veritatis ut atque dolore consequatur sapiente saepe veniam? Minus rerum accusamus, ut, molestias quo obcaecati odit libero ipsam voluptatum accusantium totam, fugiat est cupiditate facere labore recusandae aperiam veritatis molestiae provident suscipit! Provident incidunt quae delectus, cupiditate laborum maxime magnam praesentium, nesciunt qui ipsa dolore asperiores tempora! Adipisci harum quae numquam quis architecto, amet ipsum veniam nam pariatur est perferendis, atque natus aspernatur non omnis nostrum porro fugit fugiat. Quisquam, voluptas perferendis! Quos dignissimos esse illum molestiae, animi repudiandae sunt, dolore recusandae doloremque, quibusdam inventore molestias provident. Corporis accusantium necessitatibus deserunt animi pariatur iure possimus! Molestiae sit magnam officia obcaecati. Corrupti culpa nemo ex, optio veniam non illo vel quo dolores deleniti fuga itaque eius quibusdam perspiciatis laudantium delectus nam quas quia enim eaque! Nihil atque a sed harum cumque magni doloremque saepe tempora adipisci, minus quis illum, labore nostrum laborum ipsa. Animi, quisquam. Dolores, blanditiis nihil laborum natus dolorem delectus tempora sit corrupti aliquid ab possimus cumque doloribus sapiente maiores doloremque rem? Magni modi aspernatur officiis omnis quisquam dolores! Dolorum fugiat quae odio voluptates deleniti eveniet voluptatum, ab excepturi porro expedita dolor modi optio molestiae aut molestias deserunt dolorem laudantium. Velit nobis doloribus alias, adipisci quo rerum, eius harum error cumque repellendus tenetur animi in suscipit? Iusto aliquid velit aut nulla, corporis libero impedit. Voluptatem cum voluptas voluptate, eius fugiat quasi quibusdam dignissimos quos quis quae omnis excepturi quod inventore atque, optio placeat soluta nam consequatur? Iusto nisi nobis maxime corporis illo soluta sit eius necessitatibus quidem distinctio saepe quas deleniti quos reiciendis dolorem rem facere magnam, sed, ex eveniet rerum consequuntur! Ipsam ipsum aperiam consequuntur ducimus quaerat omnis assumenda, odit saepe accusantium laboriosam similique? Optio placeat, similique veritatis illo cumque vitae porro rem saepe libero esse quis, nulla animi fugiat cupiditate modi minima ipsa nobis perspiciatis! Aut, quo iure. Velit itaque vero totam pariatur eum iusto obcaecati distinctio voluptas nobis laboriosam similique alias harum doloremque facere ducimus est, quasi cum repudiandae assumenda! Laborum rerum quam quia consequatur, perferendis fugiat dolor corrupti inventore placeat ex autem? Non minima nemo sit deserunt omnis nisi, dolore quo tempore, eos ipsum eum cumque tempora ipsam placeat optio earum nihil expedita vero nobis a. Harum porro, quia magni eum cumque minus, sed quas natus quos odit laborum, officia possimus ea aliquam nobis animi iusto nesciunt! Ipsum laboriosam voluptate, vel ullam dolores, cupiditate magnam inventore consectetur nesciunt velit, similique facere optio. Maxime, aut laudantium. Eaque laboriosam architecto ipsam rerum, nisi, iste quis quidem illo ratione, aspernatur incidunt libero animi aperiam placeat ducimus? Voluptas, ad alias labore cum maxime perferendis quis vitae voluptate, inventore repellat doloremque aspernatur reiciendis expedita rerum impedit cumque? Labore doloribus possimus recusandae quasi hic inventore sit qui consequatur delectus ea quibusdam, veritatis eligendi reprehenderit, eveniet minus blanditiis iusto voluptates voluptatem. Laborum quae repellat, recusandae, soluta vero fugiat ducimus qui adipisci suscipit, doloribus asperiores! Labore odit commodi perferendis voluptas sunt. Suscipit quidem fuga delectus unde sit alias nemo corporis non assumenda id? Saepe iste officia in repellat nulla eum veniam nemo voluptate impedit fugit sed neque magnam voluptatum dolorem minus quis alias, voluptas, quaerat cupiditate voluptates? Minus expedita nisi molestiae inventore dolor quia ea quae optio nemo nostrum corrupti at perspiciatis incidunt est deserunt, maiores rerum neque aut accusamus impedit quibusdam sed libero architecto. Amet perferendis eaque nemo beatae odit eos ea nisi minima asperiores tempora voluptatibus, incidunt est iure exercitationem tenetur. Quis qui recusandae fugiat cumque, magnam vel autem dolorum delectus cum quo a amet, impedit, hic minus adipisci magni minima nemo animi est aliquid! Reiciendis ea nihil doloremque repellat exercitationem sapiente deleniti, provident incidunt, ipsa dolores a maxime! Labore quo sint consectetur error ad aut reiciendis ipsam. Sit id placeat aspernatur libero error quasi. Suscipit voluptatum fugit quia, ab, dignissimos debitis a ea qui fugiat, mollitia deserunt iste? Reprehenderit temporibus rerum debitis repellendus sapiente itaque velit sunt laudantium possimus similique architecto quas facilis ipsum quo blanditiis deleniti voluptatum iure earum exercitationem sint delectus, accusamus a. Aliquid sapiente distinctio odit officiis dolores, facere assumenda quaerat aperiam excepturi ratione recusandae exercitationem illum voluptatem nobis magnam consequatur mollitia, magni amet temporibus illo, voluptatum sed similique! Eos numquam dolores, voluptates perferendis nam quaerat sit placeat excepturi beatae.');
        $this->assertArrayHasKey('embedding', $result);
        $this->assertArrayHasKey('tokensProcessed', $result);
        $this->assertArrayHasKey('totalDuration', $result);
        $this->assertGreaterThan(0, $result['totalDuration']);
        $this->assertGreaterThan(0, $result['tokensProcessed']);
    }

    public function testEmbeddingDimensions(): void
    {
        $ollama = new Ollama();
        $agent = new Agent($ollama);
        $content = [
            'hi',
            'hello world',
            'this is a short sentence for testing',
            'In the midst of chaos, there is also opportunity — this simple truth often reveals itself when we least expect it.',
            'Artificial intelligence models like Ollama generate embeddings that map words, phrases, and even full documents into high-dimensional vector spaces where semantic similarity can be computed efficiently.', // long paragraph (~35 words)
            str_repeat('This is a repeated pattern. ', 100),
        ];
        foreach ($content as $text) {
            $result = $agent->embed($text);
            $this->assertArrayHasKey('embedding', $result);
            $this->assertArrayHasKey('tokensProcessed', $result);
            $this->assertArrayHasKey('totalDuration', $result);
            $this->assertGreaterThan(0, $result['totalDuration']);
            $this->assertGreaterThan(0, $result['tokensProcessed']);

            $embedding = $result['embedding'];
            $dimension = count($embedding);

            $this->assertSame($ollama->getEmbeddingDimension(), $dimension);
        }
    }
}
