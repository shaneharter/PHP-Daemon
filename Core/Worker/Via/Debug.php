<?php















                        $out[] = 'call [f] [a,b..]  Call a worker\'s function in the local process, passing remaining values as args. Return true: a "continue" will be implied. Non-true: keep you at the prompt';
                        $out[] = 'cleanipc          Clean all systemv resources including shared memory and message queues. Does not remove semaphores. REQUIRES CONFIRMATION.  ';


                        $out[] = 'show [n]          Display the Nth item in shared memory. If no ID is passed, `show` will show the shared memory header.';
                        $out[] = 'show local [n]    Display the Nth item in local memory - from the $this->calls array';
                        $out[] = 'status            Display current process stats';
                        $out[] = 'types             Display a table of message types and statuses so you can figure out what they mean.';